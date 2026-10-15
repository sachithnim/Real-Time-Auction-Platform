<?php

namespace Tests\Feature\Bidding;

use App\Enums\AuctionEventType;
use App\Enums\BidType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\User;
use App\Services\Bidding\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidServiceTest extends TestCase
{
    use RefreshDatabase;

    private BidService $bids;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bids = app(BidService::class);
    }

    private function liveAuction(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create(array_merge([
            'starting_price' => 20_000,
            'reserve_price' => null,
            'min_increment' => 500,
        ], $attributes));
    }

    private function assertRejected(ErrorCode $code, callable $action): ApiException
    {
        try {
            $action();
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode, $e->getMessage());

            return $e;
        }

        $this->fail("Expected the bid to be rejected with {$code->value}.");
    }

    // --- Accepted bids ------------------------------------------------------

    public function test_first_bid_at_the_starting_price_is_accepted(): void
    {
        $auction = $this->liveAuction();
        $bidder = User::factory()->create();

        $result = $this->bids->placeBid($auction, $bidder, 20_000);

        $this->assertSame(20_000, $result->bid->amount);
        $this->assertSame(BidType::Manual, $result->bid->type);
        $this->assertSame(20_500, $result->minNextBid);

        $auction->refresh();
        $this->assertSame(20_000, $auction->current_price);
        $this->assertSame($bidder->id, $auction->leader_id);
    }

    public function test_assignment_example_25000_with_increment_500_requires_25500(): void
    {
        $auction = $this->liveAuction(['starting_price' => 10_000]);
        $this->bids->placeBid($auction, User::factory()->create(), 25_000);
        $challenger = User::factory()->create();

        $rejected = $this->assertRejected(
            ErrorCode::BidTooLow,
            fn () => $this->bids->placeBid($auction, $challenger, 25_499),
        );
        $this->assertSame(25_500, $rejected->details['min_next_bid']);

        $result = $this->bids->placeBid($auction, $challenger, 25_500);
        $this->assertSame(25_500, $result->auction->current_price);
        $this->assertSame($challenger->id, $result->auction->leader_id);
    }

    public function test_a_bid_above_the_minimum_is_accepted_at_its_full_amount(): void
    {
        $auction = $this->liveAuction();

        $result = $this->bids->placeBid($auction, User::factory()->create(), 31_000);

        $this->assertSame(31_000, $result->auction->current_price);
    }

    public function test_dynamic_tier_increment_applies_per_bid(): void
    {
        // Auction minimum (100) is below the tier, so the tier decides.
        $auction = $this->liveAuction(['starting_price' => 49_500, 'min_increment' => 100]);
        $this->bids->placeBid($auction, User::factory()->create(), 50_000);

        // 50,000 is in the 500 tier.
        $this->assertSame(50_500, $this->bids->placeBid($auction, User::factory()->create(), 50_500)->auction->current_price);
        // 50,500 is in the 1,000 tier.
        $this->assertRejected(ErrorCode::BidTooLow, fn () => $this->bids->placeBid($auction, User::factory()->create(), 51_000));
        $this->assertSame(51_500, $this->bids->placeBid($auction, User::factory()->create(), 51_500)->auction->current_price);
    }

    public function test_accepted_bid_records_bid_placed_and_leader_changed(): void
    {
        $auction = $this->liveAuction();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->bids->placeBid($auction, $first, 20_000);
        $this->bids->placeBid($auction, $second, 21_000);

        $events = AuctionEvent::orderBy('id')->get();
        $this->assertSame([
            AuctionEventType::BidPlaced, AuctionEventType::LeaderChanged,
            AuctionEventType::BidPlaced, AuctionEventType::LeaderChanged,
        ], $events->pluck('type')->all());

        $lastBid = $events[2]->payload;
        $this->assertSame(20_000, $lastBid['previous_price']);
        $this->assertSame(21_000, $lastBid['new_price']);
        $this->assertSame(20_500, $lastBid['required_minimum']);

        $leaderChange = $events[3]->payload;
        $this->assertSame($first->id, $leaderChange['previous_leader_id']);
        $this->assertSame($second->id, $leaderChange['new_leader_id']);
    }

    public function test_bid_history_order_follows_acceptance_order(): void
    {
        $auction = $this->liveAuction();
        $amounts = [20_000, 20_500, 21_000, 22_000];

        foreach ($amounts as $amount) {
            $this->bids->placeBid($auction, User::factory()->create(), $amount);
        }

        $this->assertSame($amounts, $auction->bids()->pluck('amount')->all());
    }

    // --- Rejected bids ------------------------------------------------------

    public function test_bid_below_the_starting_price_is_rejected(): void
    {
        $auction = $this->liveAuction();

        $rejected = $this->assertRejected(
            ErrorCode::BidTooLow,
            fn () => $this->bids->placeBid($auction, User::factory()->create(), 19_999),
        );

        $this->assertSame(20_000, $rejected->details['min_next_bid']);
        $this->assertSame(0, Bid::count());
        $this->assertNull($auction->fresh()->current_price);
    }

    public function test_leader_cannot_outbid_themselves(): void
    {
        $auction = $this->liveAuction();
        $leader = User::factory()->create();
        $this->bids->placeBid($auction, $leader, 20_000);

        $rejected = $this->assertRejected(
            ErrorCode::InvalidBid,
            fn () => $this->bids->placeBid($auction, $leader, 30_000),
        );

        $this->assertSame('ALREADY_LEADING', $rejected->details['reason']);
        $this->assertSame(20_000, $auction->fresh()->current_price);
    }

    public function test_rejected_bid_is_audited_without_changing_the_auction(): void
    {
        $auction = $this->liveAuction();
        $bidder = User::factory()->create();

        $this->assertRejected(ErrorCode::BidTooLow, fn () => $this->bids->placeBid($auction, $bidder, 100));

        $event = AuctionEvent::sole();
        $this->assertSame(AuctionEventType::BidRejected, $event->type);
        $this->assertSame($bidder->id, $event->actor_id);
        $this->assertSame(100, $event->payload['amount']);
        $this->assertSame('BID_TOO_LOW', $event->payload['reason']);
        $this->assertSame(20_000, $event->payload['details']['min_next_bid']);
        $this->assertSame(0, Bid::count());
    }

    // --- Time window and status ---------------------------------------------

    public function test_bid_before_the_start_time_is_rejected(): void
    {
        $auction = Auction::factory()->scheduled()->create();

        $rejected = $this->assertRejected(
            ErrorCode::AuctionNotStarted,
            fn () => $this->bids->placeBid($auction, User::factory()->create(), $auction->starting_price),
        );

        $this->assertArrayHasKey('server_time', $rejected->details);
    }

    public function test_bid_after_end_time_is_rejected_even_before_the_close_job_runs(): void
    {
        $auction = $this->liveAuction();
        $this->travelTo($auction->end_at->addSecond());

        $this->assertRejected(
            ErrorCode::AuctionEnded,
            fn () => $this->bids->placeBid($auction, User::factory()->create(), 20_000),
        );
        $this->assertSame(0, Bid::count());
    }

    public function test_bid_exactly_at_the_end_time_is_rejected(): void
    {
        $auction = $this->liveAuction();
        $this->travelTo($auction->end_at);

        $this->assertRejected(
            ErrorCode::AuctionEnded,
            fn () => $this->bids->placeBid($auction, User::factory()->create(), 20_000),
        );
    }

    public function test_bid_one_second_before_the_end_time_is_accepted(): void
    {
        $auction = $this->liveAuction();
        $this->travelTo($auction->end_at->subSecond());

        $this->assertSame(20_000, $this->bids->placeBid($auction, User::factory()->create(), 20_000)->bid->amount);
    }

    public function test_completed_auction_rejects_bids(): void
    {
        $auction = Auction::factory()->completed()->create();

        $this->assertRejected(
            ErrorCode::AuctionEnded,
            fn () => $this->bids->placeBid($auction, User::factory()->create(), 1_000_000),
        );
    }

    public function test_cancelled_auction_rejects_bids(): void
    {
        $auction = $this->liveAuction();
        $auction->forceFill(['status' => 'CANCELLED', 'cancelled_at' => now()])->save();

        $this->assertRejected(
            ErrorCode::AuctionNotLive,
            fn () => $this->bids->placeBid($auction, User::factory()->create(), 20_000),
        );
    }

    public function test_draft_auction_rejects_bids(): void
    {
        $auction = Auction::factory()->create();

        $this->assertRejected(
            ErrorCode::AuctionNotLive,
            fn () => $this->bids->placeBid($auction, User::factory()->create(), $auction->starting_price),
        );
    }

    public function test_scheduled_auction_past_its_start_waits_for_the_start_job(): void
    {
        $auction = Auction::factory()->scheduled()->create([
            'start_at' => now()->subSeconds(10),
            'end_at' => now()->addHour(),
            'original_end_at' => now()->addHour(),
        ]);

        $this->assertRejected(
            ErrorCode::AuctionNotLive,
            fn () => $this->bids->placeBid($auction, User::factory()->create(), $auction->starting_price),
        );
    }
}
