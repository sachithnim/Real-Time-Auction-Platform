<?php

namespace Tests\Feature\Auth;

use App\Models\Auction;
use App\Models\User;
use App\Notifications\VerifyEmailNotice;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function unverified(): User
    {
        return User::factory()->unverified()->create(['email' => 'asha@example.com']);
    }

    public function test_signing_up_sends_a_queued_confirmation_email(): void
    {
        $this->withHeader('Origin', 'http://localhost:5173')->postJson('/api/auth/register', [
            'first_name' => 'Asha', 'last_name' => 'Perera', 'email' => 'asha@example.com',
            'password' => 'secret-pass', 'password_confirmation' => 'secret-pass',
        ])->assertCreated()->assertJsonPath('data.email_verified', false);

        $user = User::where('email', 'asha@example.com')->sole();
        Notification::assertSentTo($user, VerifyEmailNotice::class, function (VerifyEmailNotice $notice) use ($user) {
            $html = (string) $notice->toMail($user)->render();
            $this->assertInstanceOf(ShouldQueue::class, $notice);
            $this->assertStringContainsString('/api/email/verify/'.$user->id.'/', $html);
            $this->assertStringContainsString('signature=', $html);

            return true;
        });
    }

    public function test_the_link_confirms_the_email_without_logging_in(): void
    {
        $user = $this->unverified();

        $this->get(VerifyEmailNotice::url($user))->assertRedirect('http://localhost:5173/email-verified?status=verified');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_opening_it_again_says_already_confirmed(): void
    {
        $user = $this->unverified();
        $url = VerifyEmailNotice::url($user);

        $this->get($url);
        $this->get($url)->assertRedirect('http://localhost:5173/email-verified?status=already');
    }

    public function test_a_tampered_expired_or_outdated_link_does_not_confirm(): void
    {
        $user = $this->unverified();
        $invalid = 'http://localhost:5173/email-verified?status=invalid';

        $this->get(VerifyEmailNotice::url($user).'x')->assertRedirect($invalid);

        $expired = VerifyEmailNotice::url($user);
        $this->travel(VerifyEmailNotice::EXPIRE_HOURS + 1)->hours();
        $this->get($expired)->assertRedirect($invalid);
        $this->travelBack();

        // A link for the address before an email change.
        $old = VerifyEmailNotice::url($user);
        $user->forceFill(['email' => 'asha.new@example.com'])->save();
        $this->get($old)->assertRedirect($invalid);

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unverified_bidder_can_browse_and_watch_but_not_bid_or_pay(): void
    {
        $user = $this->unverified();
        $auction = Auction::factory()->live()->create(['starting_price' => 10_000]);

        $this->actingAs($user)->getJson("/api/auctions/{$auction->id}")->assertOk();
        $this->actingAs($user)->putJson("/api/auctions/{$auction->id}/watch")->assertOk();

        foreach (["/api/auctions/{$auction->id}/bids" => ['amount' => 10_000], "/api/auctions/{$auction->id}/payment" => []] as $url => $body) {
            $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson($url, $body)
                ->assertForbidden()
                ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
        }
        $this->actingAs($user)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->putJson("/api/auctions/{$auction->id}/auto-bid", ['max_amount' => 20_000])
            ->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->assertSame(0, $auction->bids()->count());
    }

    public function test_the_email_can_be_sent_again_and_not_once_confirmed(): void
    {
        $user = $this->unverified();

        $this->actingAs($user)->postJson('/api/me/email/verification-notification')->assertStatus(202);
        Notification::assertSentToTimes($user, VerifyEmailNotice::class, 1);

        $user->markEmailAsVerified();
        $this->actingAs($user)->postJson('/api/me/email/verification-notification')->assertNoContent();
        Notification::assertSentToTimes($user, VerifyEmailNotice::class, 1);
    }

    public function test_changing_the_email_needs_the_new_address_confirmed(): void
    {
        $user = User::factory()->create(['email' => 'asha@example.com']);

        $this->actingAs($user)->putJson('/api/me/profile', [
            'first_name' => 'Asha', 'last_name' => 'Perera', 'email' => 'asha.new@example.com', 'current_password' => 'password',
        ])->assertOk()->assertJsonPath('data.email_verified', false);

        Notification::assertSentTo($user, VerifyEmailNotice::class);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }
}
