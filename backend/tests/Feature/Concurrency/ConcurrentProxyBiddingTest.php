<?php

namespace Tests\Feature\Concurrency;

use App\Enums\AuctionEventType;
use App\Enums\BidType;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\User;
use PHPUnit\Framework\Attributes\Group;

#[Group('concurrency')]
class ConcurrentProxyBiddingTest extends ConcurrencyTestCase
{
    public function test_simultaneous_auto_and_manual_bids_always_resolve_to_the_same_result(): void
    {
        // Every one of the 6 possible orders of these three actions ends with A leading at 15,500.
        $rounds = [];
        for ($i = 0; $i < 4; $i++) {
            $auction = Auction::factory()->live()->create([
                'starting_price' => 10_000, 'reserve_price' => null, 'min_increment' => 500,
                'anti_snipe_seconds' => 0, 'extension_seconds' => 0, 'max_extensions' => 0,
            ]);
            [$a, $b, $c] = User::factory()->count(3)->create()->all();
            $rounds[] = compact('auction', 'a', 'b', 'c');
        }

        $jobs = [];
        foreach ($rounds as $r) {
            $jobs[] = ['action' => 'auto', 'auction' => $r['auction']->id, 'user' => $r['a']->id, 'amount' => 20_000];
            $jobs[] = ['action' => 'auto', 'auction' => $r['auction']->id, 'user' => $r['b']->id, 'amount' => 15_000];
            $jobs[] = ['action' => 'bid', 'auction' => $r['auction']->id, 'user' => $r['c']->id, 'amount' => 12_000];
        }

        $results = array_chunk($this->runConcurrently($jobs), 3);

        foreach ($rounds as $i => $r) {
            [$aResult, $bResult, $cResult] = $results[$i];
            $auction = $r['auction']->fresh();

            $this->assertTrue($aResult['ok'] && $bResult['ok'], "Round {$i}: both maximums are valid when set.");
            $this->assertTrue($cResult['ok'] || $cResult['code'] === 'BID_TOO_LOW', "Round {$i}: C is either accepted or too late.");

            $this->assertSame($r['a']->id, $auction->leader_id, "Round {$i}: highest maximum leads.");
            $this->assertSame(15_500, $auction->current_price, "Round {$i}: second maximum plus one increment.");

            $last = Bid::where('auction_id', $auction->id)->orderByDesc('id')->first();
            $this->assertSame([$r['a']->id, 15_500, BidType::Auto], [$last->user_id, $last->amount, $last->type]);
            $this->assertTrue(AutoBid::where('user_id', $r['a']->id)->sole()->is_active);
            $this->assertFalse(AutoBid::where('user_id', $r['b']->id)->sole()->is_active);

            $amounts = Bid::where('auction_id', $auction->id)->orderBy('id')->pluck('amount')->all();
            $sorted = $amounts;
            sort($sorted);
            $this->assertSame($sorted, $amounts, "Round {$i}: the visible history never goes down.");

            $recordedBids = AuctionEvent::where('auction_id', $auction->id)
                ->whereIn('type', [AuctionEventType::BidPlaced, AuctionEventType::AutoBidPlaced])->count();
            $this->assertSame(count($amounts), $recordedBids, "Round {$i}: one audit event per visible bid.");
        }
    }
}
