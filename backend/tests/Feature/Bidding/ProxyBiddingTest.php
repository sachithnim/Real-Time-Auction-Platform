<?php

namespace Tests\Feature\Bidding;

use App\Enums\AuctionEventType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\AutoBid;
use App\Models\User;
use App\Services\Bidding\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProxyBiddingTest extends TestCase
{
    use RefreshDatabase;

    private BidService $bids;

    private Auction $auction;

    private User $a;

    private User $b;

    private User $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bids = app(BidService::class);
        // Assignment example setup: increment 500.
        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 10_000,
            'reserve_price' => null,
            'min_increment' => 500,
        ]);
        [$this->a, $this->b, $this->c] = User::factory()->count(3)->create()->all();
    }

    /**
     * @return list<array{int, int, string}>
     */
    private function history(): array
    {
        return $this->auction->bids()->get()
            ->map(fn ($bid) => [$bid->user_id, $bid->amount, $bid->type->value])
            ->all();
    }

    private function state(): array
    {
        $fresh = $this->auction->fresh();

        return [$fresh->leader_id, $fresh->current_price];
    }

    private function rejected(ErrorCode $code, callable $action): ApiException
    {
        try {
            $action();
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);

            return $e;
        }

        $this->fail("Expected {$code->value}.");
    }

    // --- The assignment example ---------------------------------------------

    public function test_assignment_example_a_20000_and_b_15000_ends_at_15500_with_a_leading(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);
        $this->travel(1)->seconds();
        $result = $this->bids->configureAutoBid($this->auction, $this->b, 15_000);

        $this->assertSame([$this->a->id, 15_500], $this->state());
        $this->assertSame([
            [$this->a->id, 10_000, 'AUTO'],  // A opens at the starting price
            [$this->b->id, 15_000, 'AUTO'],  // B goes as far as its maximum
            [$this->a->id, 15_500, 'AUTO'],  // A answers with just one increment
        ], $this->history());

        // B's maximum can no longer win: switched off and reported.
        $this->assertFalse(AutoBid::where('user_id', $this->b->id)->sole()->is_active);
        $this->assertSame([$this->b->id], $result->exhaustedUserIds);
        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::AutoBidMaxReached)->count());

        // A's maximum is still active and unchanged.
        $this->assertSame(20_000, AutoBid::where('user_id', $this->a->id)->sole()->max_amount);
    }

    // --- Manual bids meet auto-bids -----------------------------------------

    public function test_a_lower_manual_bid_is_accepted_and_immediately_answered(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);

        $result = $this->bids->placeBid($this->auction, $this->c, 12_000);

        $this->assertSame(12_000, $result->bid->amount, 'The manual bid itself is accepted and recorded.');
        $this->assertSame([$this->a->id, 12_500], $this->state());
        $this->assertSame(13_000, $result->minNextBid);
        $this->assertSame([], $result->outbidUserIds, 'A never lost the lead.');
    }

    public function test_a_manual_bid_above_the_maximum_takes_the_lead_at_its_amount(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);

        $result = $this->bids->placeBid($this->auction, $this->c, 25_000);

        $this->assertSame([$this->c->id, 25_000], $this->state());
        $this->assertSame([$this->a->id], $result->outbidUserIds);
        $this->assertSame([$this->a->id], $result->exhaustedUserIds);
        $this->assertFalse(AutoBid::where('user_id', $this->a->id)->sole()->is_active);
    }

    public function test_a_manual_bid_equal_to_the_maximum_loses_to_the_earlier_commitment(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);
        $this->travel(1)->seconds();

        $this->bids->placeBid($this->auction, $this->c, 20_000);

        $this->assertSame([$this->a->id, 20_000], $this->state());
        $this->assertSame([
            [$this->a->id, 10_000, 'AUTO'],
            [$this->c->id, 20_000, 'MANUAL'],
            [$this->a->id, 20_000, 'AUTO'],
        ], $this->history());
    }

    public function test_an_auto_bid_takes_the_lead_from_a_manual_leader_with_one_increment(): void
    {
        $this->bids->placeBid($this->auction, $this->b, 15_000);

        $result = $this->bids->configureAutoBid($this->auction, $this->a, 20_000);

        $this->assertSame([$this->a->id, 15_500], $this->state());
        $this->assertSame([$this->b->id], $result->outbidUserIds);
    }

    // --- Competing auto-bidders ---------------------------------------------

    public function test_equal_maximums_go_to_whoever_set_theirs_first(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);
        $this->travel(1)->seconds();
        $this->bids->configureAutoBid($this->auction, $this->b, 20_000);

        $this->assertSame([$this->a->id, 20_000], $this->state());
        $this->assertFalse(AutoBid::where('user_id', $this->b->id)->sole()->is_active);
    }

    public function test_a_bidding_war_between_three_auto_bidders(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);
        $this->travel(1)->seconds();
        $this->bids->configureAutoBid($this->auction, $this->b, 26_000);
        $this->assertSame([$this->b->id, 20_500], $this->state());

        $this->travel(1)->seconds();
        $this->bids->configureAutoBid($this->auction, $this->c, 30_000);
        $this->assertSame([$this->c->id, 26_500], $this->state());

        // Only the leader's maximum is still active.
        $this->assertSame([$this->c->id], AutoBid::where('is_active', true)->pluck('user_id')->all());
    }

    public function test_an_exhausted_bidder_can_come_back_with_a_higher_maximum(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);
        $this->travel(1)->seconds();
        $this->bids->configureAutoBid($this->auction, $this->b, 15_000);
        $this->travel(1)->seconds();

        $this->bids->configureAutoBid($this->auction, $this->b, 30_000);

        $this->assertSame([$this->b->id, 20_500], $this->state());
        $this->assertTrue(AutoBid::where('user_id', $this->b->id)->sole()->is_active);
    }

    // --- The leader's own maximum -------------------------------------------

    public function test_leader_can_raise_their_maximum_without_a_new_visible_bid(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);

        $result = $this->bids->configureAutoBid($this->auction, $this->a, 30_000);

        $this->assertSame([], $result->bids);
        $this->assertSame([$this->a->id, 10_000], $this->state());
        $this->assertSame(30_000, AutoBid::where('user_id', $this->a->id)->sole()->max_amount);
        $this->assertCount(1, $this->history());
    }

    public function test_a_manual_leader_can_add_a_maximum_that_then_defends(): void
    {
        $this->bids->placeBid($this->auction, $this->a, 12_000);
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);

        $this->bids->placeBid($this->auction, $this->c, 14_000);

        $this->assertSame([$this->a->id, 14_500], $this->state());
    }

    public function test_a_maximum_cannot_be_lowered(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);

        $rejected = $this->rejected(
            ErrorCode::MaxAutoBidReached,
            fn () => $this->bids->configureAutoBid($this->auction, $this->a, 18_000),
        );

        $this->assertSame(20_000, $rejected->details['minimum_max_amount']);
        $this->assertSame(20_000, AutoBid::where('user_id', $this->a->id)->sole()->max_amount);
    }

    public function test_a_maximum_below_the_next_valid_bid_is_rejected(): void
    {
        $this->bids->placeBid($this->auction, $this->a, 15_000);

        $rejected = $this->rejected(
            ErrorCode::MaxAutoBidReached,
            fn () => $this->bids->configureAutoBid($this->auction, $this->b, 15_200),
        );

        $this->assertSame(15_500, $rejected->details['min_next_bid']);
        $this->assertSame(0, AutoBid::count());
    }

    public function test_resubmitting_the_same_maximum_keeps_its_original_priority(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);
        $setAt = AutoBid::where('user_id', $this->a->id)->sole()->max_set_at;
        $this->travel(5)->seconds();

        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);

        $this->assertTrue($setAt->eq(AutoBid::where('user_id', $this->a->id)->sole()->max_set_at));
    }

    public function test_auto_bid_follows_the_same_time_window_as_bids(): void
    {
        $this->travelTo($this->auction->end_at);

        $this->rejected(ErrorCode::AuctionEnded, fn () => $this->bids->configureAutoBid($this->auction, $this->a, 20_000));
        $this->assertSame(0, AutoBid::count());
    }

    // --- Audit trail --------------------------------------------------------

    public function test_auto_bids_are_audited_with_enough_detail_to_replay_the_auction(): void
    {
        $this->bids->configureAutoBid($this->auction, $this->a, 20_000);
        $this->travel(1)->seconds();
        $this->bids->configureAutoBid($this->auction, $this->b, 15_000);

        $this->assertSame([
            'AUTO_BID_CONFIGURED', 'AUTO_BID_PLACED', 'LEADER_CHANGED',
            'AUTO_BID_CONFIGURED', 'AUTO_BID_PLACED', 'AUTO_BID_PLACED', 'AUTO_BID_MAX_REACHED',
        ], AuctionEvent::orderBy('id')->pluck('type')->map->value->all());

        $answer = AuctionEvent::where('type', AuctionEventType::AutoBidPlaced)->orderByDesc('id')->first();
        $this->assertNull($answer->actor_id, 'Automatic answers are system actions.');
        $this->assertSame(15_000, $answer->payload['previous_price']);
        $this->assertSame(15_500, $answer->payload['new_price']);
    }
}
