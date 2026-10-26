<?php

namespace Tests\Feature\Realtime;

use App\Events\Admin\AdminMonitorEvent;
use App\Events\Admin\MonitorAuctionChanged;
use App\Events\Admin\MonitorBidRejected;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\User;
use App\Services\AuctionLifecycleService;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use App\Services\Realtime\AdminMonitor;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class AdminMonitorEventsTest extends TestCase
{
    use RefreshDatabase;

    private BidService $bids;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MonitorAuctionChanged::class, MonitorBidRejected::class]);
        $this->bids = app(BidService::class);
    }

    private function liveAuction(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create([
            'starting_price' => 10_000, 'reserve_price' => null, 'min_increment' => 500, ...$attributes,
        ]);
    }

    public function test_an_accepted_bid_refreshes_the_auction_on_the_admin_channel(): void
    {
        $auction = $this->liveAuction();
        $bidder = User::factory()->create(['email' => 'asha@example.com']);

        $this->bids->placeBid($auction, $bidder, 10_000);

        Event::assertDispatched(MonitorAuctionChanged::class, function (MonitorAuctionChanged $event) use ($auction) {
            $row = $event->broadcastWith()['auction'];

            return $event->auctionId === $auction->id
                && $event->broadcastOn()[0]->name === 'private-admin.monitor'
                && $event->broadcastAs() === 'MonitorAuctionChanged'
                && $row['current_price'] === 10_000
                && $row['leader']['email'] === 'asha@example.com'
                && $row['sequence'] === $auction->fresh()->event_sequence;
        });
    }

    public function test_a_rejected_bid_is_reported_to_the_admin_channel_only(): void
    {
        $auction = $this->liveAuction();
        $bidder = User::factory()->create(['email' => 'nimal@example.com']);

        try {
            $this->bids->placeBid($auction, $bidder, 1);
        } catch (ApiException) {
        }

        Event::assertDispatched(MonitorBidRejected::class, function (MonitorBidRejected $event) use ($auction) {
            $rejection = $event->broadcastWith()['rejection'];

            return $event->broadcastOn()[0]->name === 'private-admin.monitor'
                && $rejection['auction_id'] === $auction->id
                && $rejection['amount'] === 1
                && $rejection['reason'] === 'BID_TOO_LOW'
                && $rejection['bidder']['email'] === 'nimal@example.com';
        });
        Event::assertNotDispatched(MonitorAuctionChanged::class);
    }

    public function test_start_close_and_cancel_refresh_the_monitor(): void
    {
        $admin = User::factory()->admin()->create();
        $scheduled = Auction::factory()->scheduled()->create(['start_at' => now()->subMinute()]);
        $ending = $this->liveAuction();
        $ending->forceFill(['end_at' => now()->subSecond()])->save();
        $cancelled = $this->liveAuction();

        app(AuctionLifecycleService::class)->start($scheduled->id);
        app(AuctionCloser::class)->close($ending->id);
        app(AuctionLifecycleService::class)->cancel($cancelled, $admin, 'Item withdrawn');

        $rows = [];
        Event::assertDispatched(MonitorAuctionChanged::class, function (MonitorAuctionChanged $event) use (&$rows) {
            $rows[$event->auctionId] = $event->broadcastWith()['auction']['status'];

            return true;
        });

        $this->assertSame(
            [$scheduled->id => 'LIVE', $ending->id => 'COMPLETED', $cancelled->id => 'CANCELLED'],
            $rows,
            'The monitor adds the started auction and drops the finished ones.',
        );
    }

    public function test_a_rolled_back_bid_tells_the_monitor_nothing(): void
    {
        $auction = $this->liveAuction();

        try {
            DB::transaction(function () use ($auction) {
                $this->bids->placeBid($auction, User::factory()->create(), 10_000);
                throw new RuntimeException('Something failed after the bid in the same transaction.');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(MonitorAuctionChanged::class);
    }

    public function test_a_missing_auction_is_reported_as_gone(): void
    {
        $this->assertSame(['id' => 999_999, 'status' => null], app(AdminMonitor::class)->rowFor(999_999));
    }

    public function test_monitor_events_are_private_and_sent_only_after_commit(): void
    {
        foreach ([MonitorAuctionChanged::class, MonitorBidRejected::class] as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldDispatchAfterCommit::class), "{$class} must wait for commit.");
            $this->assertTrue(is_subclass_of($class, AdminMonitorEvent::class));
        }
    }
}
