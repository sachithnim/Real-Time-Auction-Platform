<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Requests from the SPA origin get session (stateful) authentication.
        $this->withHeader('Origin', 'http://localhost:5173');
    }

    public function test_user_can_register_and_is_logged_in_as_a_bidder(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => 'Asha',
            'last_name' => 'Perera',
            'email' => 'asha@example.com',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
        ]);

        $response->assertCreated()
            ->assertExactJson(['data' => [
                'id' => $response->json('data.id'),
                'first_name' => 'Asha',
                'last_name' => 'Perera',
                'name' => 'Asha Perera',
                'email' => 'asha@example.com',
                'role' => 'BIDDER',
            ]]);

        $user = User::where('email', 'asha@example.com')->firstOrFail();
        $this->assertSame(UserRole::Bidder, $user->role);
        $this->assertSame(['Asha', 'Perera', 'Asha Perera'], [$user->first_name, $user->last_name, $user->name]);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_registration_cannot_choose_the_admin_role(): void
    {
        $this->postJson('/api/auth/register', [
            'first_name' => 'Mallory',
            'last_name' => 'Smith',
            'email' => 'mallory@example.com',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'role' => 'ADMIN',
        ])->assertCreated()->assertJsonPath('data.role', 'BIDDER');
    }

    public function test_registration_validates_input(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->postJson('/api/auth/register', [
            'first_name' => '',
            'last_name' => '',
            'email' => 'taken@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['first_name', 'last_name', 'email', 'password']]]]);

        $this->assertGuest('web');
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create(['email' => 'bidder@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'bidder@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.password');

        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_remember_me_sets_a_long_lived_remember_cookie(): void
    {
        $user = User::factory()->create(['email' => 'bidder@example.com']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'bidder@example.com',
            'password' => 'password',
            'remember' => true,
        ])->assertOk();

        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === Auth::guard('web')->getRecallerName());
        $this->assertGreaterThan(now()->addYear()->getTimestamp(), $cookie->getExpiresTime());
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_login_without_remember_me_sets_no_remember_cookie(): void
    {
        User::factory()->create(['email' => 'bidder@example.com']);

        $this->postJson('/api/auth/login', [
            'email' => 'bidder@example.com',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
    }

    public function test_remember_must_be_a_boolean(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => 'bidder@example.com',
            'password' => 'password',
            'remember' => 'forever',
        ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['remember']]]]);
    }

    public function test_logout_replaces_the_remember_token(): void
    {
        $user = User::factory()->create(['email' => 'bidder@example.com']);
        $this->postJson('/api/auth/login', ['email' => 'bidder@example.com', 'password' => 'password', 'remember' => true]);
        $tokenAtLogin = $user->fresh()->remember_token;

        $this->postJson('/api/auth/logout')->assertNoContent();

        // A stolen remember cookie stops working once the user logs out.
        $this->assertNotSame($tokenAtLogin, $user->fresh()->remember_token);
    }

    public function test_wrong_password_and_unknown_email_get_the_same_error(): void
    {
        User::factory()->create(['email' => 'bidder@example.com']);

        $wrongPassword = $this->postJson('/api/auth/login', [
            'email' => 'bidder@example.com',
            'password' => 'wrong-password',
        ]);
        $unknownEmail = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password',
        ]);

        $wrongPassword->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertSame($wrongPassword->json('error'), $unknownEmail->json('error'));
        $this->assertGuest('web');
    }

    public function test_current_user_endpoint_returns_the_logged_in_user(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.role', 'ADMIN');
    }

    public function test_current_user_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_login_without_the_web_app_origin_gets_a_clear_error_not_a_crash(): void
    {
        $user = User::factory()->create();

        // No Origin/Referer header: Sanctum does not start a session for this request.
        $this->withHeaders(['Origin' => '', 'Referer' => ''])
            ->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'STATEFUL_REQUEST_REQUIRED')
            ->assertJsonPath('error.details.required_origin', 'http://localhost:5173');
    }

    public function test_register_without_the_web_app_origin_gets_a_clear_error(): void
    {
        $this->withHeaders(['Origin' => '', 'Referer' => ''])
            ->postJson('/api/auth/register', [])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'STATEFUL_REQUEST_REQUIRED');
    }

    public function test_session_endpoint_returns_null_for_guests_without_an_error(): void
    {
        $this->getJson('/api/auth/session')
            ->assertOk()
            ->assertExactJson(['data' => null]);
    }

    public function test_session_endpoint_returns_the_signed_in_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/auth/session')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'BIDDER')
            ->assertJsonMissingPath('data.password');
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->postJson('/api/auth/logout')
            ->assertNoContent();

        $this->assertGuest('web');
    }

    public function test_login_is_rate_limited_per_email(): void
    {
        User::factory()->create(['email' => 'bidder@example.com']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'bidder@example.com',
                'password' => 'wrong-password',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/auth/login', [
            'email' => 'bidder@example.com',
            'password' => 'password',
        ])
            ->assertTooManyRequests()
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');

        $this->assertGuest('web');
    }
}
