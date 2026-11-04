<?php

namespace Tests\Feature;

use App\Enums\AccountChange;
use App\Models\User;
use App\Notifications\AccountSecurityEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use RefreshDatabase;

    private User $asha;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->asha = User::factory()->create(['first_name' => 'Asha', 'last_name' => 'Perera', 'name' => 'Asha Perera', 'email' => 'asha@example.com']);
    }

    private function updateProfile(array $data, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->asha)->putJson('/api/me/profile', [
            'first_name' => 'Asha', 'last_name' => 'Perera', 'email' => 'asha@example.com', ...$data,
        ]);
    }

    private function updatePassword(array $data = [])
    {
        return $this->actingAs($this->asha)->putJson('/api/me/password', [
            'current_password' => 'password',
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
            ...$data,
        ]);
    }

    // --- Profile ---------------------------------------------------------------------------

    public function test_a_user_renames_themselves_without_their_password(): void
    {
        $this->updateProfile(['first_name' => 'Ashani', 'last_name' => 'Silva'])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Ashani')
            ->assertJsonPath('data.name', 'Ashani Silva');

        $this->assertSame('Ashani Silva', $this->asha->fresh()->name);
        Notification::assertNothingSent();
    }

    public function test_changing_the_email_needs_the_current_password(): void
    {
        $this->updateProfile(['email' => 'asha.new@example.com'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['current_password']]]]);

        $this->updateProfile(['email' => 'asha.new@example.com', 'current_password' => 'wrong-password'])->assertUnprocessable();

        $this->assertSame('asha@example.com', $this->asha->fresh()->email);
    }

    public function test_a_changed_email_is_saved_and_the_old_address_is_told(): void
    {
        $this->updateProfile(['email' => 'asha.new@example.com', 'current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.email', 'asha.new@example.com');

        Notification::assertSentOnDemand(
            AccountSecurityEmail::class,
            fn (AccountSecurityEmail $email, array $channels, AnonymousNotifiable $to) => $to->routes['mail'] === 'asha@example.com'
                && $email->change === AccountChange::EmailChanged
                && $email->newEmail === 'asha.new@example.com',
        );
    }

    public function test_the_email_notice_offers_no_reset_link_that_would_reach_the_new_address(): void
    {
        $html = (string) (new AccountSecurityEmail(AccountChange::EmailChanged, 'Asha', 'asha.new@example.com'))
            ->toMail(new AnonymousNotifiable)->render();

        $this->assertStringContainsString('asha.new@example.com', $html);
        $this->assertStringNotContainsString('/forgot-password', $html);
    }

    public function test_the_same_email_in_other_case_is_not_a_change(): void
    {
        $this->updateProfile(['email' => 'ASHA@example.com'])->assertOk();
    }

    public function test_an_email_already_in_use_is_rejected(): void
    {
        User::factory()->create(['email' => 'ravi@example.com']);

        $this->updateProfile(['email' => 'ravi@example.com', 'current_password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['email']]]]);
    }

    public function test_the_role_cannot_be_changed_through_the_profile(): void
    {
        $this->updateProfile(['role' => 'ADMIN'])->assertOk()->assertJsonPath('data.role', 'BIDDER');
    }

    public function test_admins_can_edit_their_own_profile_too(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'admin@example.com']);

        $this->updateProfile(['first_name' => 'Nimal', 'email' => 'admin@example.com'], $admin)->assertOk()->assertJsonPath('data.first_name', 'Nimal');
    }

    // --- Password --------------------------------------------------------------------------

    public function test_a_user_changes_their_password_and_is_told_by_email(): void
    {
        $this->updatePassword()->assertNoContent();

        $this->assertTrue(Hash::check('brand-new-pass', $this->asha->fresh()->password));
        Notification::assertSentTo($this->asha, AccountSecurityEmail::class, fn ($email) => $email->change === AccountChange::PasswordChanged);
    }

    public function test_a_wrong_current_password_changes_nothing(): void
    {
        $this->updatePassword(['current_password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.errors.current_password.0', 'Your current password is not correct.');

        $this->assertTrue(Hash::check('password', $this->asha->fresh()->password));
        Notification::assertNothingSent();
    }

    public function test_the_new_password_must_be_long_confirmed_and_different(): void
    {
        $this->updatePassword(['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();
        $this->updatePassword(['password_confirmation' => 'something-else'])->assertUnprocessable();
        $this->updatePassword(['password' => 'password', 'password_confirmation' => 'password'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.errors.password.0', 'Choose a password different from your current one.');
    }

    public function test_changing_the_password_signs_out_other_devices(): void
    {
        config(['session.driver' => 'database']);
        $this->asha->forceFill(['remember_token' => 'old-remember-token'])->save();
        $ravi = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'ashas-phone', 'user_id' => $this->asha->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'ravis-laptop', 'user_id' => $ravi->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->updatePassword()->assertNoContent();

        $this->assertDatabaseMissing('sessions', ['id' => 'ashas-phone']);
        $this->assertDatabaseHas('sessions', ['id' => 'ravis-laptop']);
        $this->assertNotSame('old-remember-token', $this->asha->fresh()->remember_token);
    }

    public function test_guests_cannot_change_an_account(): void
    {
        $this->putJson('/api/me/profile', [])->assertUnauthorized();
        $this->putJson('/api/me/password', [])->assertUnauthorized();
    }
}
