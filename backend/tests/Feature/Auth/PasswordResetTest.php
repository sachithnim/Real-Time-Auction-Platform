<?php

namespace Tests\Feature\Auth;

use App\Enums\AccountChange;
use App\Models\User;
use App\Notifications\AccountSecurityEmail;
use App\Notifications\ResetPasswordEmail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private User $asha;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withHeader('Origin', 'http://localhost:5173');
        $this->asha = User::factory()->create(['email' => 'asha@example.com', 'first_name' => 'Asha']);
    }

    private function reset(array $overrides = [])
    {
        // A new token replaces the previous one, so only make one when the test gives none.
        return $this->postJson('/api/auth/reset-password', [
            'email' => 'asha@example.com',
            'token' => $overrides['token'] ?? Password::broker()->createToken($this->asha),
            'password' => 'brand-new-pass',
            'password_confirmation' => 'brand-new-pass',
            ...$overrides,
        ]);
    }

    public function test_forgot_password_emails_a_queued_link_to_the_web_app(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'asha@example.com'])->assertStatus(202);

        Notification::assertSentTo($this->asha, ResetPasswordEmail::class, function (ResetPasswordEmail $email) {
            $mail = $email->toMail($this->asha);
            $html = (string) $mail->render();

            $this->assertInstanceOf(ShouldQueue::class, $email);
            $this->assertSame('Reset your LiveBid password', $mail->subject);
            $this->assertStringContainsString('http://localhost:5173/reset-password?token='.$email->token.'&amp;email=asha%40example.com', $html);
            $this->assertStringContainsString('expires in 60 minutes', $html);

            return true;
        });
    }

    public function test_an_unknown_email_gets_the_same_answer_and_no_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertStatus(202);

        Notification::assertNothingSent();
    }

    public function test_forgot_password_needs_a_valid_email(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])->assertUnprocessable();
    }

    public function test_a_valid_link_sets_the_new_password_and_signs_out_everywhere(): void
    {
        $this->asha->forceFill(['remember_token' => 'old-remember-token'])->save();

        $this->reset()->assertNoContent();

        $this->asha->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $this->asha->password));
        $this->assertNotSame('old-remember-token', $this->asha->remember_token);
        Notification::assertSentTo($this->asha, AccountSecurityEmail::class, fn ($email) => $email->change === AccountChange::PasswordReset);

        $this->postJson('/api/auth/login', ['email' => 'asha@example.com', 'password' => 'brand-new-pass'])->assertOk();
    }

    public function test_a_link_works_only_once(): void
    {
        $token = Password::broker()->createToken($this->asha);

        $this->reset(['token' => $token])->assertNoContent();
        $this->reset(['token' => $token, 'password' => 'another-pass-1', 'password_confirmation' => 'another-pass-1'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.errors.token.0', 'This reset link is invalid or has expired. Ask for a new one.');
    }

    public function test_a_wrong_or_expired_token_is_rejected_and_the_password_kept(): void
    {
        $this->reset(['token' => 'made-up-token'])->assertUnprocessable();

        $token = Password::broker()->createToken($this->asha);
        $this->travel(61)->minutes();
        $this->reset(['token' => $token])->assertUnprocessable();

        $this->assertTrue(Hash::check('password', $this->asha->fresh()->password));
    }

    public function test_an_unknown_email_on_reset_reads_like_a_bad_token(): void
    {
        $this->reset(['email' => 'nobody@example.com'])
            ->assertUnprocessable()
            ->assertJsonPath('error.details.errors.token.0', 'This reset link is invalid or has expired. Ask for a new one.');
    }

    public function test_the_new_password_follows_the_registration_rules(): void
    {
        $this->reset(['password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable()->assertJsonPath('error.details.errors.password.0', fn ($m) => str_contains($m, '8'));
        $this->reset(['password_confirmation' => 'something-else'])->assertUnprocessable();
    }

    public function test_forgot_password_is_rate_limited_per_email(): void
    {
        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'asha@example.com'])->assertStatus(202);
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'asha@example.com'])->assertTooManyRequests();
    }
}
