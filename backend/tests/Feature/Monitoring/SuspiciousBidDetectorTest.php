<?php

namespace Tests\Feature\Monitoring;

use App\Enums\AuctionEventType;
use App\Enums\FlagRule;
use App\Enums\FlagStatus;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\BidFlag;
use App\Models\User;
use App\Services\Monitoring\SuspiciousBidDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuspiciousBidDetectorTest extends TestCase
{
    use RefreshDatabase;

    private Auction $auction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        $this->auction = Auction::factory()->live()->create();
    }

    private function scan(User $user, ?string $ip = null): void
    {
        app(SuspiciousBidDetector::class)->scan($this->auction->id, $user->id, $ip);
    }

    private function bids(User $user, int $count, bool $auto = false): void
    {
        $factory = Bid::factory()->for($this->auction)->for($user)->count($count);
        ($auto ? $factory->auto() : $factory)->create();
    }

    private function rejections(User $user, int $count, string $reason = 'BID_TOO_LOW'): void
    {
        foreach (range(1, $count) as $i) {
            AuctionEvent::create([
                'auction_id' => $this->auction->id, 'type' => AuctionEventType::BidRejected, 'actor_id' => $user->id,
                'payload' => ['action' => 'BID', 'bidder_id' => $user->id, 'amount' => $i, 'reason' => $reason],
            ]);
        }
    }

    private function leadChanges(int $count): void
    {
        for ($made = 0; $made < $count; $made++) {
            AuctionEvent::create(['auction_id' => $this->auction->id, 'type' => AuctionEventType::LeaderChanged, 'payload' => []]);
        }
    }

    // --- Rapid bidding ---------------------------------------------------------

    public function test_ten_manual_bids_within_a_minute_are_flagged(): void
    {
        $bidder = User::factory()->create();
        $this->bids($bidder, 10);

        $this->scan($bidder);

        $flag = BidFlag::sole();
        $this->assertSame(FlagRule::RapidBidding, $flag->rule);
        $this->assertSame(FlagStatus::Open, $flag->status);
        $this->assertSame($bidder->id, $flag->user_id);
        $this->assertNull($flag->related_user_id);
        $this->assertEquals(['bids' => 10, 'window_seconds' => 60], $flag->details);
    }

    public function test_nine_bids_or_automatic_answers_are_not_rapid_bidding(): void
    {
        $bidder = User::factory()->create();
        $this->bids($bidder, 9);
        $this->bids($bidder, 5, auto: true); // placed by the system from a maximum

        $this->scan($bidder);

        $this->assertSame(0, BidFlag::count());
    }

    public function test_bids_older_than_the_window_do_not_count(): void
    {
        $bidder = User::factory()->create();
        $this->bids($bidder, 10);
        $this->travel(61)->seconds();

        $this->scan($bidder);

        $this->assertSame(0, BidFlag::count());
    }

    // --- Many rejections ---------------------------------------------------------

    public function test_five_rejections_within_five_minutes_are_flagged_with_their_reasons(): void
    {
        $bidder = User::factory()->create();
        $this->rejections($bidder, 3, 'BID_TOO_LOW');
        $this->rejections($bidder, 2, 'AUCTION_ENDED');

        $this->scan($bidder);

        $flag = BidFlag::sole();
        $this->assertSame(FlagRule::ManyRejections, $flag->rule);
        $this->assertSame(5, $flag->details['rejections']);
        $this->assertEquals(['BID_TOO_LOW' => 3, 'AUCTION_ENDED' => 2], $flag->details['reasons']);
    }

    public function test_four_rejections_are_not_flagged(): void
    {
        $bidder = User::factory()->create();
        $this->rejections($bidder, 4);

        $this->scan($bidder);

        $this->assertSame(0, BidFlag::count());
    }

    // --- Shared IP ---------------------------------------------------------------

    public function test_two_bidding_accounts_on_one_ip_are_flagged_together(): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();
        $this->bids($a, 1);
        $this->bids($b, 1);

        $this->scan($a, '203.0.113.7');
        $this->scan($b, '203.0.113.7');

        $flag = BidFlag::sole();
        $this->assertSame(FlagRule::SharedIp, $flag->rule);
        $this->assertSame([min($a->id, $b->id), max($a->id, $b->id)], [$flag->user_id, $flag->related_user_id]);
        $this->assertSame(['ip_addresses' => ['203.0.113.7']], $flag->details);
    }

    public function test_a_shared_ip_without_bids_from_both_accounts_is_not_flagged(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        $this->bids($a, 1);
        $this->bids($c, 1);

        $this->scan($a, '203.0.113.7');
        $this->scan($b, '203.0.113.7'); // same IP, but no accepted bid
        $this->scan($c, '198.51.100.20'); // bids, different IP

        $this->assertSame(0, BidFlag::count());
    }

    // --- Alternating leaders -------------------------------------------------------

    public function test_two_bidders_trading_the_lead_six_times_are_flagged(): void
    {
        [$a, $b] = User::factory()->count(2)->create()->all();
        $this->bids($a, 3);
        $this->bids($b, 3);
        $this->leadChanges(6);

        $this->scan($a);

        $flag = BidFlag::sole();
        $this->assertSame(FlagRule::AlternatingLeaders, $flag->rule);
        // MySQL JSON columns store object keys in their own order, so compare by key.
        $this->assertEquals(['lead_changes' => 6, 'bidders' => 2], $flag->details);
    }

    public function test_a_third_bidder_or_fewer_lead_changes_is_normal_competition(): void
    {
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        $this->bids($a, 3);
        $this->bids($b, 3);
        $this->leadChanges(5);
        $this->scan($a);
        $this->assertSame(0, BidFlag::count(), 'Five changes is below the threshold.');

        $this->bids($c, 1);
        $this->leadChanges(3);
        $this->scan($a);
        $this->assertSame(0, BidFlag::count(), 'Three bidders is open competition.');
    }

    // --- One flag per pattern ------------------------------------------------------

    public function test_seeing_a_pattern_again_updates_one_flag_and_reopens_it_after_review(): void
    {
        $bidder = User::factory()->create();
        $admin = User::factory()->admin()->create();
        $this->bids($bidder, 10);

        $this->scan($bidder);
        $this->scan($bidder);
        $this->assertSame(2, BidFlag::sole()->occurrences);

        BidFlag::sole()->forceFill(['status' => FlagStatus::Reviewed, 'reviewed_by' => $admin->id, 'reviewed_at' => now()])->save();
        $this->bids($bidder, 1);
        $this->scan($bidder);

        $flag = BidFlag::sole();
        $this->assertSame(FlagStatus::Open, $flag->status, 'The pattern continued after review.');
        $this->assertSame(3, $flag->occurrences);
        $this->assertSame(11, $flag->details['bids']);
    }
}
