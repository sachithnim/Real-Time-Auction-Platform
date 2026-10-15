<?php

namespace App\Http\Middleware;

use App\Enums\ErrorCode;
use App\Enums\IdempotencyStatus;
use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes a state-changing request safe to retry. The client sends an
 * Idempotency-Key header (one UUID per user action, reused on retries):
 *
 *  - first time: the key is claimed as PROCESSING (unique per user, so only one
 *    request can claim it), the request runs and its final response is stored;
 *  - same key + same request, finished: the stored response is replayed;
 *  - same key + same request, still running: 409 DUPLICATE_REQUEST;
 *  - same key + different request: 422 IDEMPOTENCY_KEY_REUSED.
 *
 * Retryable outcomes (5xx, BID_CONFLICT_RETRY, 429) are not stored, so the
 * client can retry them with the same key.
 */
class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    /** A PROCESSING key older than this is treated as abandoned (e.g. a crashed worker). */
    private const ABANDONED_AFTER_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->key($request);
        $fingerprint = $this->fingerprint($request);

        $claim = $this->claim($request->user()->id, $key, $fingerprint);
        if ($claim instanceof Response) {
            return $claim;
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $claim->delete();

            throw $e;
        }

        if ($this->isRetryable($response)) {
            $claim->delete();

            return $response;
        }

        $claim->forceFill([
            'status' => IdempotencyStatus::Completed,
            'response_code' => $response->getStatusCode(),
            'response_body' => json_decode((string) $response->getContent(), true),
        ])->save();

        return $response;
    }

    private function key(Request $request): string
    {
        $key = trim((string) $request->header(self::HEADER));

        if ($key === '' || strlen($key) > 100 || ! preg_match('/^[A-Za-z0-9_\-.:]+$/', $key)) {
            throw new ApiException(ErrorCode::ValidationFailed, 'A valid Idempotency-Key header is required.', [
                'errors' => [self::HEADER => ['Send a unique key (for example a UUID) of up to 100 characters for each action.']],
            ]);
        }

        return $key;
    }

    /** Same method, path and body (key order ignored) → same fingerprint. */
    private function fingerprint(Request $request): string
    {
        $input = $request->all();
        $this->sortRecursively($input);

        return hash('sha256', $request->method().' '.$request->path().' '.json_encode($input));
    }

    /**
     * @param  array<mixed>  $data
     */
    private function sortRecursively(array &$data): void
    {
        ksort($data);
        foreach ($data as &$value) {
            if (is_array($value)) {
                $this->sortRecursively($value);
            }
        }
    }

    /**
     * Claims the key, or returns the response to send instead (a replay).
     */
    private function claim(int $userId, string $key, string $fingerprint): IdempotencyKey|Response
    {
        try {
            return IdempotencyKey::create([
                'user_id' => $userId,
                'key' => $key,
                'request_hash' => $fingerprint,
                'status' => IdempotencyStatus::Processing,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request already claimed this key; decide below.
        }

        $existing = IdempotencyKey::where('user_id', $userId)->where('key', $key)->first();

        if ($existing === null) {
            // The earlier attempt released the key (retryable failure) in the meantime.
            return $this->claim($userId, $key, $fingerprint);
        }

        if (! hash_equals($existing->request_hash, $fingerprint)) {
            throw new ApiException(
                ErrorCode::IdempotencyKeyReused,
                'This Idempotency-Key was already used for a different request.',
            );
        }

        if ($existing->status === IdempotencyStatus::Completed) {
            return $this->replay($existing);
        }

        if ($this->takeOverIfAbandoned($existing)) {
            return $existing->fresh();
        }

        throw new ApiException(ErrorCode::DuplicateRequest, 'This request is already being processed.');
    }

    private function takeOverIfAbandoned(IdempotencyKey $existing): bool
    {
        if ($existing->updated_at->gt(now()->subSeconds(self::ABANDONED_AFTER_SECONDS))) {
            return false;
        }

        // Conditional update: if two retries race for an abandoned key, only one wins.
        return IdempotencyKey::whereKey($existing->id)
            ->where('status', IdempotencyStatus::Processing)
            ->where('updated_at', $existing->getRawOriginal('updated_at'))
            ->update(['updated_at' => now()]) === 1;
    }

    private function replay(IdempotencyKey $record): JsonResponse
    {
        return new JsonResponse($record->response_body, $record->response_code, ['Idempotent-Replayed' => 'true']);
    }

    private function isRetryable(Response $response): bool
    {
        if ($response->getStatusCode() >= 500 || $response->getStatusCode() === 429) {
            return true;
        }

        $body = json_decode((string) $response->getContent(), true);

        return ($body['error']['code'] ?? null) === ErrorCode::BidConflictRetry->value;
    }
}
