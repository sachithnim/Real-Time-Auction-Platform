<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])->prefix('api/_test')->group(function () {
            Route::get('admin-only', fn () => 'ok')->middleware('role:ADMIN');
            Route::get('bidder-only', fn () => 'ok')->middleware('role:BIDDER');
        });
    }

    public function test_admin_can_access_admin_routes(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/_test/admin-only')
            ->assertOk();
    }

    public function test_bidder_cannot_access_admin_routes(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/_test/admin-only')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_admin_cannot_access_bidder_only_routes(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/_test/bidder-only')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_guest_is_rejected_before_the_role_check(): void
    {
        $this->getJson('/api/_test/admin-only')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_new_users_default_to_bidder_and_role_is_not_mass_assignable(): void
    {
        $user = User::create([
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'password',
            'role' => 'ADMIN',
        ]);

        $this->assertSame(UserRole::Bidder, $user->fresh()->role);
    }
}
