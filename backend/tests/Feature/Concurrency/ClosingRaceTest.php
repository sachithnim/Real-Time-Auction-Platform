<?php

namespace Tests\Feature\Concurrency;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\User;
use App\Services\Bidding\BidService;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Group;

#[Group('concurrency')]
class ClosingRaceTest extends ConcurrencyTestCase
{
    public function test_three_closers_at_once_close_the_auction_exactly_once(): void
    {
        $auction = Auction::factory()->live()->create(['reserve_price' => null]);
        $winner = User::factory()->create();
        app(BidService::class)->placeBid($auction, $winner, $auction->starting_price);
        $auction->forceFill(['end_at' => now()->subSecond()])->save();

        $results = $this->runConcurrently(array_fill(0, 3, ['action' => 'close', 'auction' => $auction->id]));

        $this->assertEqualsCanonicalizing(['CLOSED', 'SKIPPED', 'SKIPPED'], array_column($results, 'status'));
        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::AuctionEnded)->count());
        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::WinnerSelected)->count());
        $this->assertSame($winner->id, $auction->fresh()->winner_id);
    }

    public function test_a_bid_racing_the_close_is_either_in_the_result_or_rejected(): void
    {
        $releaseAt = $this->releaseTimeAfterBoot() + 1;
        $endAt = (int) ceil($releaseAt) + 1; // whole second: end_at has second precision

        // Bids land from 60 ms before to 60 ms after the end, each racing its own closer.
        $offsets = [-0.06, -0.02, -0.005, 0.0, 0.005, 0.02, 0.06];
        $jobs = [];
        $cases = [];
        foreach ($offsets as $offset) {
            $auction = Auction::factory()->live()->create([
                'reserve_price' => null,
                'anti_snipe_seconds' => 0, 'extension_seconds' => 0, 'max_extensions' => 0,
                'end_at' => Carbon::createFromTimestamp($endAt),
                'original_end_at' => Carbon::createFromTimestamp($endAt),
            ]);
            $bidder = User::factory()->create();
            $cases[] = compact('auction', 'bidder', 'offset');

            $delay = $endAt - $releaseAt;
            $jobs[] = ['action' => 'bid', 'auction' => $auction->id, 'user' => $bidder->id, 'amount' => $auction->starting_price, 'delay' => $delay + $offset];
            $jobs[] = ['action' => 'close', 'auction' => $auction->id, 'delay' => $delay - 0.1, 'timeout' => 5];
        }

        $results = array_chunk($this->runConcurrently($jobs, $releaseAt), 2);

        // The clear-cut cases must land on the expected side of the end time.
        $this->assertTrue($results[0][0]['ok'], 'A bid 60 ms before the end is accepted.');
        $this->assertFalse(end($results)[0]['ok'], 'A bid 60 ms after the end is rejected.');

        foreach ($cases as $i => $case) {
            [$bid, $close] = $results[$i];
            $auction = $case['auction']->fresh();

            $this->assertSame('CLOSED', $close['status'], "Offset {$case['offset']}: the closer closed it.");
            $this->assertSame(AuctionStatus::Completed, $auction->status);

            if ($bid['ok']) {
                // Accepted before the end: it must be the winning bid.
                $this->assertSame(AuctionResult::Sold, $auction->result, "Offset {$case['offset']}: accepted bid lost.");
                $this->assertSame($case['bidder']->id, $auction->winner_id);
                $this->assertSame($auction->starting_price, $auction->final_price);
            } else {
                // Too late: refused, and nothing was stored.
                $this->assertSame('AUCTION_ENDED', $bid['code'], "Offset {$case['offset']}");
                $this->assertSame(AuctionResult::NoBids, $auction->result);
                $this->assertSame(0, Bid::where('auction_id', $auction->id)->count());
            }
        }
    }
}
