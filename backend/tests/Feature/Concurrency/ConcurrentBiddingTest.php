<?php

namespace Tests\Feature\Concurrency;

use App\Enums\AuctionEventType;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\User;
use App\Services\Bidding\BidService;
use App\Services\Bidding\IncrementCalculator;
use PHPUnit\Framework\Attributes\Group;

#[Group('concurrency')]
class ConcurrentBiddingTest extends ConcurrencyTestCase
{
    private function liveAuction(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create(array_merge([
            'starting_price' => 40_000,
            'reserve_price' => null,
            'min_increment' => 500,
            'anti_snipe_seconds' => 0,
            'extension_seconds' => 0,
            'max_extensions' => 0,
        ], $attributes));
    }

    /**
     * The history must be a valid serial sequence of manual bids, and the
     * auction row and audit trail must agree with it exactly.
     */
    private function assertConsistentManualHistory(Auction $auction): void
    {
        $auction->refresh();
        $bids = Bid::where('auction_id', $auction->id)->orderBy('id')->get();
        $increments = app(IncrementCalculator::class);

        for ($i = 1; $i < $bids->count(); $i++) {
            $previous = $bids[$i - 1];
            $required = $previous->amount + $increments->incrementFor($previous->amount, $auction);
            $this->assertGreaterThanOrEqual($required, $bids[$i]->amount, "Bid {$bids[$i]->id} was accepted below the minimum.");
            $this->assertNotSame($previous->user_id, $bids[$i]->user_id, 'A leader outbid themselves.');
        }

        $last = $bids->last();
        $this->assertSame($last->amount, $auction->current_price, 'Price does not match the last accepted bid.');
        $this->assertSame($last->user_id, $auction->leader_id, 'Leader does not match the last accepted bid.');

        $placed = AuctionEvent::where('auction_id', $auction->id)->where('type', AuctionEventType::BidPlaced)->get();
        $this->assertSame($bids->pluck('id')->all(), $placed->map(fn ($e) => $e->payload['bid_id'])->all(), 'Audit trail and bids disagree.');
    }

    public function test_assignment_example_three_simultaneous_bids_leave_a_valid_deterministic_state(): void
    {
        $bids = app(BidService::class);
        $rounds = [];

        for ($round = 0; $round < 5; $round++) {
            $auction = $this->liveAuction();
            $bids->placeBid($auction, User::factory()->create(), 50_000); // current price 50,000
            [$a, $b, $c] = User::factory()->count(3)->create()->all();
            $rounds[] = compact('auction', 'a', 'b', 'c');
        }

        $jobs = [];
        foreach ($rounds as $r) {
            foreach ([[$r['a'], 51_000], [$r['b'], 51_500], [$r['c'], 51_000]] as [$user, $amount]) {
                $jobs[] = ['action' => 'bid', 'auction' => $r['auction']->id, 'user' => $user->id, 'amount' => $amount];
            }
        }

        $results = array_chunk($this->runConcurrently($jobs), 3);

        foreach ($rounds as $i => $r) {
            $outcomes = $results[$i];
            $accepted = array_values(array_filter($outcomes, fn ($o) => $o['ok']));
            $rejected = array_values(array_filter($outcomes, fn ($o) => ! $o['ok']));

            // Whichever bid took the lock first is the only one still valid afterwards.
            $this->assertCount(1, $accepted, "Round {$i}: exactly one bid must win.");
            $this->assertSame(['BID_TOO_LOW', 'BID_TOO_LOW'], array_column($rejected, 'code'));

            $winningBid = Bid::findOrFail($accepted[0]['bid_id']);
            $auction = $r['auction']->fresh();
            $this->assertContains($winningBid->amount, [51_000, 51_500]);
            $this->assertSame($winningBid->amount, $auction->current_price);
            $this->assertSame($winningBid->user_id, $auction->leader_id);
            $this->assertSame(2, Bid::where('auction_id', $auction->id)->count(), 'No duplicate or lost bids.');
            $this->assertSame(2, AuctionEvent::where('auction_id', $auction->id)->where('type', AuctionEventType::BidRejected)->count());
            $this->assertConsistentManualHistory($auction);
        }
    }

    public function test_many_bidders_racing_on_one_auction_produce_a_valid_history(): void
    {
        $auction = $this->liveAuction(['starting_price' => 10_000]);
        $users = User::factory()->count(8)->create();

        $results = $this->runConcurrently($users->map(fn (User $user) => [
            'action' => 'race',
            'auction' => $auction->id,
            'user' => $user->id,
            'rounds' => 6,
        ])->all());

        $acceptedByWorkers = array_sum(array_column($results, 'accepted'));
        $this->assertSame($acceptedByWorkers, Bid::where('auction_id', $auction->id)->count(), 'Every accepted bid is stored exactly once.');
        $this->assertGreaterThan(1, $acceptedByWorkers);
        $this->assertGreaterThan(0, array_sum(array_column($results, 'rejected')), 'The race produced conflicts to resolve.');
        $this->assertConsistentManualHistory($auction);
    }
}
