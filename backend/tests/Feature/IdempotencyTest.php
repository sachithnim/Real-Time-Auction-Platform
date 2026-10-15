<?php

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Enums\IdempotencyStatus;
use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = '7f9c2ba4-e88f-4c1c-9f3a-6a1b2c3d4e5f';

    private int $runs = 0;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        Route::middleware(['api', 'auth:sanctum', 'idempotent'])->prefix('api/_test')->group(function () {
            Route::post('action', function () {
                $this->runs++;

                return response()->json(['run' => $this->runs, 'amount' => request('amount')], 201);
            });
            Route::post('rejected', function () {
                $this->runs++;
                throw new ApiException(ErrorCode::BidTooLow, 'Too low.', ['min_next_bid' => 25_500]);
            });
            Route::post('conflict', function () {
                $this->runs++;
                throw new ApiException(ErrorCode::BidConflictRetry, 'Busy.');
            });
            Route::post('crash', function () {
                $this->runs++;
                throw new RuntimeException('boom');
            });
        });
    }

    private function send(string $path, array $body = ['amount' => 25_500], string $key = self::KEY, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/_test/{$path}", $body);
    }

    public function test_the_header_is_required(): void
    {
        $this->actingAs($this->user)->postJson('/api/_test/action', ['amount' => 1])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        $this->assertSame(0, $this->runs);
    }

    public function test_a_malformed_key_is_rejected(): void
    {
        $this->send('action', key: str_repeat('x', 101))->assertUnprocessable();
        $this->send('action', key: 'has spaces in it')->assertUnprocessable();

        $this->assertSame(0, $this->runs);
    }

    public function test_a_retry_with_the_same_key_replays_the_first_response(): void
    {
        $first = $this->send('action')->assertCreated();
        $retry = $this->send('action')->assertCreated();

        $this->assertSame(1, $this->runs, 'The action must run only once.');
        $this->assertSame($first->json(), $retry->json());
        $retry->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame(IdempotencyStatus::Completed, IdempotencyKey::sole()->status);
    }

    public function test_body_key_order_does_not_change_the_fingerprint(): void
    {
        $this->send('action', ['amount' => 25_500, 'note' => 'x'])->assertCreated();
        $this->send('action', ['note' => 'x', 'amount' => 25_500])->assertCreated();

        $this->assertSame(1, $this->runs);
    }

    public function test_reusing_a_key_for_a_different_request_is_rejected(): void
    {
        $this->send('action', ['amount' => 25_500])->assertCreated();

        $this->send('action', ['amount' => 99_000])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, $this->runs);
    }

    public function test_a_request_still_in_progress_returns_duplicate_request(): void
    {
        $this->send('action')->assertCreated();
        // Simulate the first request still running.
        IdempotencyKey::query()->update(['status' => IdempotencyStatus::Processing, 'response_code' => null, 'response_body' => null]);

        $this->send('action')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_REQUEST');

        $this->assertSame(1, $this->runs);
    }

    public function test_an_abandoned_processing_key_can_be_retried(): void
    {
        $this->send('action')->assertCreated();
        DB::table('idempotency_keys')->update([
            'status' => IdempotencyStatus::Processing->value,
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->send('action')->assertCreated()->assertJsonPath('run', 2);
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $this->send('action')->assertCreated();
        $this->send('action', user: User::factory()->create())->assertCreated();

        $this->assertSame(2, $this->runs);
    }

    public function test_a_final_rejection_is_stored_and_replayed(): void
    {
        $this->send('rejected')->assertUnprocessable()->assertJsonPath('error.code', 'BID_TOO_LOW');
        $this->send('rejected')
            ->assertUnprocessable()
            ->assertJsonPath('error.details.min_next_bid', 25_500)
            ->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, $this->runs);
    }

    public function test_a_lock_conflict_is_not_stored_so_the_client_can_retry(): void
    {
        $this->send('conflict')->assertStatus(409)->assertJsonPath('error.code', 'BID_CONFLICT_RETRY');
        $this->send('conflict')->assertStatus(409);

        $this->assertSame(2, $this->runs);
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_a_server_error_is_not_stored_so_the_client_can_retry(): void
    {
        $this->send('crash')->assertStatus(500);
        $this->send('crash')->assertStatus(500);

        $this->assertSame(2, $this->runs);
        $this->assertSame(0, IdempotencyKey::count());
    }

    public function test_old_keys_are_pruned(): void
    {
        $this->send('action')->assertCreated();
        $this->send('action', key: 'recent-key')->assertCreated();
        IdempotencyKey::where('key', self::KEY)->update(['created_at' => now()->subHours(25)]);

        Artisan::call('model:prune', ['--model' => [IdempotencyKey::class]]);

        $this->assertSame(['recent-key'], IdempotencyKey::pluck('key')->all());
    }
}
