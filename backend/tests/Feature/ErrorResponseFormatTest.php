<?php

namespace Tests\Feature;

use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Tests\TestCase;

class ErrorResponseFormatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/_test')->group(function () {
            Route::get('domain', fn () => throw new ApiException(
                ErrorCode::BidTooLow,
                'Bid must be at least 25500.',
                ['min_next_bid' => 25500],
            ));
            Route::post('validation', fn () => Validator::make([], ['amount' => 'required'])->validate());
            Route::get('auth', fn () => 'secret')->middleware('auth:sanctum');
            Route::get('gate', fn () => Gate::authorize('never'));
            Route::get('throttled', fn () => throw new ThrottleRequestsException('', null, ['Retry-After' => 30]));
            Route::get('csrf', fn () => abort(419, 'CSRF token mismatch.'));
            Route::get('crash', fn () => throw new RuntimeException('database password is hunter2'));
        });

        Gate::define('never', fn () => false);
    }

    private function assertErrorShape($response, int $status, string $code): void
    {
        $response->assertStatus($status)
            ->assertExactJsonStructure(['error' => ['code', 'message', 'details']])
            ->assertJsonPath('error.code', $code);
    }

    public function test_domain_exception_renders_code_message_and_details(): void
    {
        $response = $this->getJson('/api/_test/domain');

        $this->assertErrorShape($response, 422, 'BID_TOO_LOW');
        $response->assertJsonPath('error.message', 'Bid must be at least 25500.')
            ->assertJsonPath('error.details.min_next_bid', 25500);
    }

    public function test_validation_errors_use_the_common_shape(): void
    {
        $response = $this->postJson('/api/_test/validation');

        $this->assertErrorShape($response, 422, 'VALIDATION_FAILED');
        $response->assertJsonStructure(['error' => ['details' => ['errors' => ['amount']]]]);
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        $this->assertErrorShape($this->getJson('/api/_test/auth'), 401, 'UNAUTHENTICATED');
    }

    public function test_failed_authorization_returns_403(): void
    {
        $this->assertErrorShape($this->getJson('/api/_test/gate'), 403, 'FORBIDDEN');
    }

    public function test_unknown_route_returns_404(): void
    {
        $this->assertErrorShape($this->getJson('/api/does-not-exist'), 404, 'NOT_FOUND');
    }

    public function test_wrong_method_returns_405(): void
    {
        $this->assertErrorShape($this->deleteJson('/api/_test/domain'), 405, 'METHOD_NOT_ALLOWED');
    }

    public function test_rate_limited_request_returns_429_with_retry_after(): void
    {
        $response = $this->getJson('/api/_test/throttled');

        $this->assertErrorShape($response, 429, 'TOO_MANY_REQUESTS');
        $response->assertJsonPath('error.details.retry_after', 30)
            ->assertHeader('Retry-After', 30);
    }

    public function test_csrf_token_mismatch_returns_a_specific_code(): void
    {
        $this->assertErrorShape($this->getJson('/api/_test/csrf'), 419, 'CSRF_TOKEN_MISMATCH');
    }

    public function test_unexpected_errors_do_not_leak_internals_in_production(): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/_test/crash');

        $this->assertErrorShape($response, 500, 'SERVER_ERROR');
        $this->assertStringNotContainsString('hunter2', $response->getContent());
    }

    public function test_every_error_code_maps_to_an_http_error_status(): void
    {
        foreach (ErrorCode::cases() as $code) {
            $this->assertGreaterThanOrEqual(400, $code->status(), $code->value);
        }
    }
}
