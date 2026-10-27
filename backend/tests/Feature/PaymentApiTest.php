<?php

namespace Tests\Feature;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Enums\PaymentStatus;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Payment;
use App\Models\User;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD_CARD = '4242 4242 4242 4242';

    private const DECLINED_CARD = '4000 0000 0000 0002';

    private User $winner;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->winner = User::factory()->create();
        $this->auction = Auction::factory()->completed(AuctionResult::Sold)->create([
            'starting_price' => 10_000, 'current_price' => 15_500, 'final_price' => 15_500,
            'leader_id' => $this->winner->id, 'winner_id' => $this->winner->id,
        ]);
    }

    /**
     * @param  array<string, string>  $card
     */
    private function pay(array $card = [], ?User $user = null, ?string $key = null)
    {
        return $this->actingAs($user ?? $this->winner)
            ->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson("/api/auctions/{$this->auction->id}/payment", [
                'cardholder_name' => 'Asha Perera',
                'card_number' => self::GOOD_CARD,
                'expiry' => '12/'.now()->addYears(3)->format('y'),
                'cvc' => '123',
                ...$card,
            ]);
    }

    // --- Opening the payment ---------------------------------------------------------

    public function test_closing_a_sold_auction_opens_a_pending_payment_for_the_winner(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => null]);
        $bidder = User::factory()->create();
        app(BidService::class)->placeBid($auction, $bidder, 12_000);
        $auction->forceFill(['end_at' => now()->subSecond()])->save();

        app(AuctionCloser::class)->close($auction->id);

        $payment = Payment::where('auction_id', $auction->id)->sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame($bidder->id, $payment->user_id);
        $this->assertSame(12_000, $payment->amount);
    }

    public function test_an_auction_that_did_not_sell_opens_no_payment(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => 50_000]);
        app(BidService::class)->placeBid($auction, User::factory()->create(), 12_000);
        $auction->forceFill(['end_at' => now()->subSecond()])->save();

        app(AuctionCloser::class)->close($auction->id);

        $this->assertSame(AuctionResult::ReserveNotMet, $auction->fresh()->result);
        $this->assertSame(0, Payment::where('auction_id', $auction->id)->count());
    }

    public function test_the_winner_sees_what_they_owe(): void
    {
        $this->actingAs($this->winner)->getJson("/api/auctions/{$this->auction->id}/payment")
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.amount', 15_500)
            ->assertJsonPath('data.reference', null);
    }

    // --- Paying ----------------------------------------------------------------------------

    public function test_the_winner_pays_with_a_test_card(): void
    {
        $this->pay()
            ->assertOk()
            ->assertJsonPath('data.status', 'PAID')
            ->assertJsonPath('data.amount', 15_500)
            ->assertJsonPath('data.card_brand', 'VISA')
            ->assertJsonPath('data.card_last4', '4242')
            ->assertJsonPath('data.attempts', 1);

        $payment = Payment::sole();
        $this->assertMatchesRegularExpression('/^PAY-[A-Z0-9]{10}$/', $payment->reference);
        $this->assertNotNull($payment->paid_at);

        $event = AuctionEvent::where('type', AuctionEventType::PaymentSucceeded)->sole();
        $this->assertSame($this->winner->id, $event->actor_id);
        $this->assertSame(15_500, $event->payload['amount']);
        $this->assertSame($payment->reference, $event->payload['reference']);
    }

    public function test_the_full_card_number_and_cvc_are_never_stored(): void
    {
        $this->pay(['cvc' => '987'])->assertOk();

        foreach (['payments', 'auction_events', 'idempotency_keys'] as $table) {
            $stored = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('4242424242424242', $stored, "Full card number stored in {$table}.");
            $this->assertStringNotContainsString('4242 4242 4242 4242', $stored, "Full card number stored in {$table}.");
            // As a stored value: "987" in quotes, so digits inside timestamps or references don't count.
            $this->assertStringNotContainsString('"987"', $stored, "CVC stored in {$table}.");
            $this->assertStringNotContainsString('cvc', $stored, "CVC field stored in {$table}.");
            $this->assertFalse(Schema::hasColumn($table, 'cvc'));
        }
    }

    public function test_a_declined_card_fails_and_the_winner_can_try_again(): void
    {
        $this->pay(['card_number' => self::DECLINED_CARD])
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.failure_reason', 'CARD_DECLINED')
            ->assertJsonPath('data.reference', null);
        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::PaymentFailed)->count());

        $this->pay()->assertOk()->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.attempts', 2)
            ->assertJsonPath('data.failure_reason', null);
    }

    public function test_a_paid_auction_cannot_be_paid_again(): void
    {
        $reference = $this->pay()->json('data.reference');

        $this->pay()
            ->assertConflict()
            ->assertJsonPath('error.code', 'PAYMENT_ALREADY_COMPLETED')
            ->assertJsonPath('error.details.reference', $reference);

        $this->assertSame(1, Payment::sole()->attempts);
    }

    public function test_retrying_the_same_request_charges_once(): void
    {
        $key = (string) Str::uuid();

        $this->pay(key: $key)->assertOk()->assertJsonPath('data.status', 'PAID');
        $this->pay(key: $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame(1, Payment::sole()->attempts);
        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::PaymentSucceeded)->count());
    }

    // --- Who may pay, and when -------------------------------------------------------------

    public function test_only_the_winner_can_see_or_pay(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)->getJson("/api/auctions/{$this->auction->id}/payment")->assertForbidden();
        $this->pay(user: $other)->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
        $this->pay(user: User::factory()->admin()->create())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/auctions/{$this->auction->id}/payment", [])->assertUnauthorized();

        $this->assertSame(0, (int) Payment::sum('attempts'));
    }

    public function test_there_is_nothing_to_pay_for_an_auction_that_was_not_sold(): void
    {
        $this->auction->forceFill(['result' => AuctionResult::ReserveNotMet, 'winner_id' => null, 'final_price' => null])->save();

        $this->pay()->assertConflict()->assertJsonPath('error.code', 'PAYMENT_NOT_AVAILABLE');
        $this->assertSame(0, Payment::count());
    }

    public function test_card_details_are_checked_before_anything_is_charged(): void
    {
        $this->pay(['card_number' => '4242 4242 4242 4241'])
            ->assertUnprocessable()->assertJsonPath('error.details.errors.card_number.0', 'This card number is not valid.');
        $this->pay(['expiry' => '01/20'])
            ->assertUnprocessable()->assertJsonPath('error.details.errors.expiry.0', 'This card has expired.');
        $this->pay(['expiry' => '13/30'])
            ->assertUnprocessable()->assertJsonPath('error.details.errors.expiry.0', 'Enter the expiry as MM/YY.');
        $this->pay(['cvc' => '12'])
            ->assertUnprocessable()->assertJsonPath('error.details.errors.cvc.0', 'The CVC is the 3 digits on the back of the card.');
        $this->pay(['cardholder_name' => ''])->assertUnprocessable();

        $this->assertSame(0, (int) Payment::sum('attempts'));
    }

    public function test_admins_see_the_payment_on_the_auction(): void
    {
        $this->pay()->assertOk();

        $this->actingAs(User::factory()->admin()->create())->getJson("/api/admin/auctions/{$this->auction->id}")
            ->assertOk()
            ->assertJsonPath('data.payment.status', 'PAID')
            ->assertJsonPath('data.payment.card_last4', '4242');
    }
}
