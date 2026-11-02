<?php

namespace Tests\Feature;

use App\Enums\AuctionResult;
use App\Enums\EmailType;
use App\Models\Auction;
use App\Models\User;
use App\Notifications\AuctionEmail;
use App\Notifications\AuctionWonEmail;
use App\Notifications\MaxReachedEmail;
use App\Notifications\OutbidEmail;
use App\Notifications\PaymentReceiptEmail;
use App\Notifications\WatchReminderEmail;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use App\Services\Lifecycle\WatchReminders;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailNotificationTest extends TestCase
{
    use RefreshDatabase;

    private BidService $bids;

    private User $asha;

    private User $nimal;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->bids = app(BidService::class);
        $this->asha = User::factory()->create(['first_name' => 'Asha', 'name' => 'Asha Perera', 'email' => 'asha@example.com']);
        $this->nimal = User::factory()->create(['first_name' => 'Nimal', 'name' => 'Nimal Silva', 'email' => 'nimal@example.com']);
    }

    private function live(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create(['title' => 'Antique Brass Telescope', 'starting_price' => 10_000, 'min_increment' => 500, ...$attributes]);
    }

    /** The email as the recipient would see it (HTML). */
    private function render(AuctionEmail $email, User $to): string
    {
        return (string) $email->toMail($to)->render();
    }

    // --- Which emails are sent -----------------------------------------------------

    public function test_the_previous_leader_gets_an_outbid_email(): void
    {
        $auction = $this->live();
        $this->bids->placeBid($auction, $this->asha, 10_000);
        $this->bids->placeBid($auction, $this->nimal, 11_000);

        Notification::assertSentTo($this->asha, OutbidEmail::class, function (OutbidEmail $email) use ($auction) {
            $html = $this->render($email, $this->asha);

            return $email->data['auction_id'] === $auction->id
                && str_contains($html, 'Rs. 11,000')
                && str_contains($html, 'Bid Rs. 11,500')
                && str_contains($html, config('app.frontend_url')."/auctions/{$auction->id}");
        });
        Notification::assertNotSentTo($this->nimal, OutbidEmail::class);
    }

    public function test_a_bidding_war_sends_at_most_one_outbid_email_per_ten_minutes(): void
    {
        $auction = $this->live();
        $this->bids->placeBid($auction, $this->asha, 10_000);
        $this->bids->placeBid($auction, $this->nimal, 10_500);
        $this->bids->placeBid($auction, $this->asha, 11_000);
        $this->bids->placeBid($auction, $this->nimal, 11_500);

        Notification::assertSentToTimes($this->asha, OutbidEmail::class, 1);

        $this->travel(11)->minutes();
        $this->bids->placeBid($auction, $this->asha, 12_000);
        $this->bids->placeBid($auction, $this->nimal, 12_500);

        Notification::assertSentToTimes($this->asha, OutbidEmail::class, 2);
    }

    public function test_a_passed_maximum_emails_its_owner_only_their_own_maximum(): void
    {
        $auction = $this->live();
        $this->bids->configureAutoBid($auction, $this->asha, 12_000);
        $this->travel(1)->seconds();
        $this->bids->configureAutoBid($auction, $this->nimal, 20_000);

        Notification::assertSentTo($this->asha, MaxReachedEmail::class, function (MaxReachedEmail $email) {
            $html = $this->render($email, $this->asha);

            // Asha's own maximum is shown; Nimal's 20,000 maximum and identity never are.
            return str_contains($html, 'Rs. 12,000') && ! str_contains($html, '20,000') && ! str_contains($html, 'Nimal');
        });
    }

    public function test_the_winner_gets_a_you_won_email_with_a_pay_button(): void
    {
        $auction = $this->live(['reserve_price' => null]);
        $this->bids->placeBid($auction, $this->asha, 10_000);
        $this->travelTo($auction->end_at->addSecond());

        app(AuctionCloser::class)->close($auction->id);

        Notification::assertSentTo($this->asha, AuctionWonEmail::class, function (AuctionWonEmail $email) {
            $html = $this->render($email, $this->asha);

            return str_contains($html, 'Pay now') && str_contains($html, 'Rs. 10,000');
        });
    }

    public function test_watchers_get_starting_soon_reminders_by_email(): void
    {
        $auction = Auction::factory()->scheduled()->create([
            'title' => 'Leica M6', 'start_at' => now()->addMinutes(10), 'end_at' => now()->addHours(2), 'original_end_at' => now()->addHours(2),
        ]);
        $this->asha->watch($auction);

        app(WatchReminders::class)->sendDue();

        Notification::assertSentTo($this->asha, WatchReminderEmail::class, function (WatchReminderEmail $email) {
            return $email->toMail($this->asha)->subject === 'Leica M6 starts in 10 minutes';
        });
    }

    public function test_a_successful_payment_emails_a_receipt_and_a_declined_one_does_not(): void
    {
        $auction = Auction::factory()->completed(AuctionResult::Sold)->create([
            'title' => 'Gold Pocket Watch', 'current_price' => 59_000, 'final_price' => 59_000, 'leader_id' => $this->asha->id, 'winner_id' => $this->asha->id,
        ]);
        $pay = fn (string $card) => $this->actingAs($this->asha)->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/auctions/{$auction->id}/payment", [
                'cardholder_name' => 'Asha Perera', 'card_number' => $card, 'expiry' => '12/'.now()->addYears(3)->format('y'), 'cvc' => '123',
            ]);

        $pay('4000 0000 0000 0002')->assertOk();
        Notification::assertNotSentTo($this->asha, PaymentReceiptEmail::class);

        $reference = $pay('4242 4242 4242 4242')->assertOk()->json('data.reference');
        Notification::assertSentTo($this->asha, PaymentReceiptEmail::class, function (PaymentReceiptEmail $email) use ($reference) {
            $html = $this->render($email, $this->asha);

            return str_contains($html, $reference) && str_contains($html, 'Rs. 59,000') && str_contains($html, 'VISA ending 4242');
        });
    }

    // --- Preferences and unsubscribing ------------------------------------------------

    public function test_a_switched_off_email_is_not_sent(): void
    {
        $this->asha->updateEmailPreferences(['OUTBID' => false]);
        $auction = $this->live();
        $this->bids->placeBid($auction, $this->asha, 10_000);
        $this->bids->placeBid($auction, $this->nimal, 11_000);

        Notification::assertNotSentTo($this->asha, OutbidEmail::class);
    }

    public function test_every_email_is_queued_branded_and_carries_unsubscribe_links(): void
    {
        $auction = $this->live();
        $email = new OutbidEmail(['auction_id' => $auction->id, 'title' => $auction->title, 'current_price' => 11_000, 'min_next_bid' => 11_500]);

        $this->assertInstanceOf(ShouldQueue::class, $email);
        $message = $email->toMail($this->asha);
        $html = (string) $message->render();

        $this->assertStringContainsString('Hi Asha', $html);
        $this->assertStringContainsString('LiveBid', $html);
        $this->assertStringNotContainsString('Auction Platform', $html);
        $this->assertStringContainsString('Unsubscribe from outbid emails', $html);
        $this->assertStringContainsString(config('app.frontend_url').'/account/emails', $html);
    }

    public function test_the_unsubscribe_link_works_without_logging_in(): void
    {
        $url = URL::signedRoute('email.unsubscribe', ['user' => $this->asha->id, 'type' => 'OUTBID']);

        $this->get($url)->assertRedirect(config('app.frontend_url').'/unsubscribed?type=OUTBID');

        $this->assertFalse($this->asha->fresh()->wantsEmail(EmailType::Outbid));
        $this->assertTrue($this->asha->fresh()->wantsEmail(EmailType::Won));
    }

    public function test_the_mail_apps_one_click_unsubscribe_works(): void
    {
        $url = URL::signedRoute('email.unsubscribe', ['user' => $this->asha->id, 'type' => 'REMINDERS']);

        $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertNoContent();

        $this->assertFalse($this->asha->fresh()->wantsEmail(EmailType::Reminders));
    }

    public function test_a_tampered_or_unsigned_unsubscribe_link_is_refused(): void
    {
        $url = URL::signedRoute('email.unsubscribe', ['user' => $this->asha->id, 'type' => 'OUTBID']);
        // Same signature, someone else's account.
        $forged = str_replace("/unsubscribe/{$this->asha->id}/", "/unsubscribe/{$this->nimal->id}/", $url);

        $this->get($forged)->assertForbidden();
        $this->get("/api/email/unsubscribe/{$this->nimal->id}/OUTBID")->assertForbidden();

        $this->assertTrue($this->nimal->fresh()->wantsEmail(EmailType::Outbid));
    }

    public function test_bidders_read_and_change_their_email_settings(): void
    {
        $this->actingAs($this->asha)->getJson('/api/me/email-preferences')
            ->assertOk()
            ->assertExactJson(['data' => ['OUTBID' => true, 'MAX_REACHED' => true, 'WON' => true, 'REMINDERS' => true, 'RECEIPTS' => true]]);

        $this->actingAs($this->asha)->putJson('/api/me/email-preferences', ['preferences' => ['OUTBID' => false, 'SPAM' => false]])
            ->assertOk()
            ->assertJsonPath('data.OUTBID', false)
            ->assertJsonPath('data.WON', true)
            ->assertJsonMissingPath('data.SPAM');

        $this->actingAs($this->asha)->putJson('/api/me/email-preferences', ['preferences' => ['WON' => 'maybe']])->assertUnprocessable();
    }

    public function test_only_signed_in_bidders_have_email_settings(): void
    {
        $this->getJson('/api/me/email-preferences')->assertUnauthorized();
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/me/email-preferences')->assertForbidden();
    }
}
