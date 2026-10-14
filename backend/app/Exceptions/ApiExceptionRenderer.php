<?php

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Models\Auction;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Converts every exception raised by an API request into the single
 * error shape: { "error": { "code", "message", "details" } }.
 */
class ApiExceptionRenderer
{
    /**
     * @param  array<string, mixed>  $details
     */
    public static function response(ErrorCode $code, string $message, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $code->status(), $headers);
    }

    public static function render(Throwable $e): ?JsonResponse
    {
        return match (true) {
            // Already a complete response (e.g. from a Form Request's failedAuthorization override).
            $e instanceof HttpResponseException => null,

            $e instanceof ApiException => $e->render(),

            $e instanceof ValidationException => self::response(
                ErrorCode::ValidationFailed,
                'The given data was invalid.',
                ['errors' => $e->errors()],
            ),

            $e instanceof AuthenticationException => self::response(
                ErrorCode::Unauthenticated,
                'You must be logged in to do this.',
            ),

            $e instanceof NotFoundHttpException => self::notFound($e),

            $e instanceof TooManyRequestsHttpException => self::response(
                ErrorCode::TooManyRequests,
                'Too many requests. Please slow down.',
                ['retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 0)],
                $e->getHeaders(),
            ),

            // Covers AccessDeniedHttpException (failed policies/gates) and abort(4xx).
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => self::fromStatus($e),

            default => self::serverError($e),
        };
    }

    private static function fromStatus(HttpExceptionInterface $e): JsonResponse
    {
        [$code, $message] = match ($e->getStatusCode()) {
            401 => [ErrorCode::Unauthenticated, 'You must be logged in to do this.'],
            403 => [ErrorCode::Forbidden, 'You are not allowed to do this.'],
            404 => [ErrorCode::NotFound, 'The requested resource was not found.'],
            405 => [ErrorCode::MethodNotAllowed, 'This HTTP method is not supported for this endpoint.'],
            429 => [ErrorCode::TooManyRequests, 'Too many requests. Please slow down.'],
            default => [null, null],
        };

        if ($code !== null) {
            return self::response($code, $message, headers: $e->getHeaders());
        }

        return new JsonResponse([
            'error' => [
                'code' => 'HTTP_'.$e->getStatusCode(),
                'message' => $e->getMessage() ?: 'The request could not be processed.',
                'details' => (object) [],
            ],
        ], $e->getStatusCode(), $e->getHeaders());
    }

    private static function notFound(NotFoundHttpException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof ModelNotFoundException && $previous->getModel() === Auction::class) {
            return self::response(ErrorCode::AuctionNotFound, 'Auction not found.');
        }

        return self::response(ErrorCode::NotFound, 'The requested resource was not found.');
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        // Never leak internals in production; show the cause only while debugging.
        $details = config('app.debug')
            ? ['exception' => $e::class, 'message' => $e->getMessage()]
            : [];

        return self::response(ErrorCode::ServerError, 'Something went wrong. Please try again.', $details);
    }
}
