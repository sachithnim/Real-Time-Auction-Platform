<?php

namespace Tests\Feature\Realtime;

use App\Events\Auction\AuctionCancelled;
use App\Events\Auction\AuctionChannelEvent;
use App\Events\Auction\AuctionEnded;
use App\Events\Auction\AuctionExtended;
use App\Events\Auction\AuctionStarted;
use App\Events\Auction\AuctionUpdated;
use App\Events\Auction\BidPlaced;
use App\Events\Auction\LeaderChanged;
use App\Events\Auction\PriceChanged;
use App\Events\User\AuctionWon;
use App\Events\User\AutoBidMaxReached;
use App\Events\User\Outbid;
use App\Events\User\UserNotification;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\User;
use App\Services\AuctionLifecycleService;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

class RealtimeEventsTest extends TestCase
{
    use RefreshDatabase;

    private const PUBLIC_EVENTS = [
        BidPlaced::class, PriceChanged::class, LeaderChanged::class, AuctionExtended::class,
        AuctionStarted::class, AuctionEnded::class, AuctionCancelled::class, AuctionUpdated::class,
    ];

    private const PRIVATE_EVENTS = [Outbid::class, AutoBidMaxReached::class, AuctionWon::class];

    private BidService $bids;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([...self::PUBLIC_EVENTS, ...self::PRIVATE_EVENTS]);
        $this->bids = app(BidService::class);
    }

    private function liveAuction(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create(array_merge([
            'starting_price' => 10_000, 'reserve_price' => null, 'min_increment' => 500,
            'anti_snipe_seconds' => 0, 'extension_seconds' => 0, 'max_extensions' => 0,
        ], $attributes));
    }

    /**
     * Every public event dispatched so far, in dispatch order.
     *
     * @return list<AuctionChannelEvent>
     */
    private function auctionEvents(): array
    {
        $events = [];
        foreach (Event::dispatchedEvents() as $class => $dispatches) {
            if (is_subclass_of($class, AuctionChannelEvent::class)) {
                foreach ($dispatches as $dispatch) {
                    $events[] = $dispatch[0];
                }
            }
        }
        usort($events, fn ($a, $b) => $a->sequence <=> $b->sequence);

        return $events;
    }

    private function assertNoPrivateData(array $payload): void
    {
        $keys = [];
        array_walk_recursive($payload, function ($value, $key) use (&$keys) {
            $keys[] = $key;
        });
        foreach (['user_id', 'leader_id', 'winner_id', 'email', 'max_amount', 'reserve_price'] as $private) {
            $this->assertNotContains($private, $keys, "Public event leaked '{$private}'.");
        }
    }

    // --- Bids ---------------------------------------------------------------

    public function test_an_accepted_bid_announces_the_bid_price_and_leader(): void
    {
        $auction = $this->liveAuction();

        $this->bids->placeBid($auction, User::factory()->create(), 10_000);

        $events = $this->auctionEvents();
        $this->assertSame([BidPlaced::class, PriceChanged::class, LeaderChanged::class], array_map('get_class', $events));
        $this->assertSame([1, 2, 3], array_map(fn ($e) => $e->sequence, $events));

        $bid = $events[0]->broadcastWith();
        $this->assertSame('Bidder 1', $bid['bid']['bidder']);
        $this->assertSame(10_000, $bid['bid']['amount']);
        $this->assertSame('MANUAL', $bid['bid']['type']);
        $this->assertSame(10_000, $bid['current_price']);
        $this->assertSame(10_500, $bid['min_next_bid']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $bid['server_time']);
        $this->assertSame('Bidder 1', $events[2]->broadcastWith()['leader']);

        $this->assertSame("auction.{$auction->id}", $events[0]->broadcastOn()[0]->name);
        $this->assertSame('BidPlaced', $events[0]->broadcastAs());
        $this->assertSame(3, $auction->fresh()->event_sequence, 'The sequence is persisted with the change.');

        foreach ($events as $event) {
            $this->assertNoPrivateData($event->broadcastWith());
        }
    }

    public function test_the_previous_leader_is_told_privately_they_were_outbid(): void
    {
        $auction = $this->liveAuction();
        $first = User::factory()->create();
        $this->bids->placeBid($auction, $first, 10_000);

        $this->bids->placeBid($auction, User::factory()->create(), 11_000);

        Event::assertDispatched(Outbid::class, function (Outbid $event) use ($first, $auction) {
            return $event->userId === $first->id
                && $event->broadcastOn()[0]->name === "private-user.{$first->id}"
                && $event->broadcastWith()['auction_id'] === $auction->id
                && $event->broadcastWith()['min_next_bid'] === 11_500;
        });
    }

    public function test_proxy_answers_are_announced_without_revealing_the_maximum(): void
    {
        $auction = $this->liveAuction();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->bids->configureAutoBid($auction, $a, 20_000);
        $this->travel(1)->seconds();

        $this->bids->configureAutoBid($auction, $b, 15_000);

        $placed = array_values(array_filter($this->auctionEvents(), fn ($e) => $e instanceof BidPlaced));
        $this->assertSame(
            [[10_000, 'AUTO', 'Bidder 1'], [15_000, 'AUTO', 'Bidder 2'], [15_500, 'AUTO', 'Bidder 1']],
            array_map(fn ($e) => [$e->broadcastWith()['bid']['amount'], $e->broadcastWith()['bid']['type'], $e->broadcastWith()['bid']['bidder']], $placed),
        );

        foreach ($this->auctionEvents() as $event) {
            $this->assertStringNotContainsString('20000', json_encode($event->broadcastWith()), "A's maximum leaked publicly.");
        }

        // B learns privately that their own maximum was reached; A is never told anything about it.
        Event::assertDispatched(AutoBidMaxReached::class, fn ($e) => $e->userId === $b->id && $e->broadcastWith()['max_amount'] === 15_000);
        Event::assertNotDispatched(AutoBidMaxReached::class, fn ($e) => $e->userId === $a->id);
    }

    public function test_a_sniping_bid_announces_the_new_end_time(): void
    {
        $auction = $this->liveAuction(['anti_snipe_seconds' => 120, 'extension_seconds' => 120, 'max_extensions' => 3]);
        $this->travelTo($auction->end_at->subSeconds(30));

        $this->bids->placeBid($auction, User::factory()->create(), 10_000);

        Event::assertDispatched(AuctionExtended::class, function (AuctionExtended $event) use ($auction) {
            return $event->broadcastWith()['end_at'] === $auction->fresh()->end_at->utc()->format('Y-m-d\TH:i:s\Z')
                && $event->broadcastWith()['extensions_count'] === 1;
        });
    }

    public function test_listing_channel_gets_a_summary_on_every_bid(): void
    {
        $auction = $this->liveAuction();

        $this->bids->placeBid($auction, User::factory()->create(), 10_000);

        Event::assertDispatched(AuctionUpdated::class, fn (AuctionUpdated $e) => $e->broadcastOn()[0]->name === 'auctions'
            && $e->broadcastWith()['id'] === $auction->id
            && $e->broadcastWith()['current_price'] === 10_000
            && $e->broadcastWith()['bids_count'] === 1);
    }

    public function test_rejected_bids_announce_nothing(): void
    {
        $auction = $this->liveAuction();

        try {
            $this->bids->placeBid($auction, User::factory()->create(), 1);
        } catch (ApiException) {
        }

        $this->assertSame([], $this->auctionEvents());
    }

    public function test_a_rolled_back_bid_announces_nothing(): void
    {
        $auction = $this->liveAuction();

        try {
            DB::transaction(function () use ($auction) {
                $this->bids->placeBid($auction, User::factory()->create(), 10_000);
                throw new RuntimeException('Something failed after the bid in the same transaction.');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->auctionEvents(), 'Events must only be sent after commit.');
        Event::assertNotDispatched(AuctionUpdated::class);
    }

    // --- Lifecycle ----------------------------------------------------------

    public function test_scheduling_announces_the_auction_to_listing_pages(): void
    {
        $admin = User::factory()->admin()->create();
        $draft = Auction::factory()->create(['title' => 'Signed bat']);

        app(AuctionLifecycleService::class)->schedule($draft, $admin);

        Event::assertDispatched(AuctionUpdated::class, fn (AuctionUpdated $e) => $e->broadcastOn()[0]->name === 'auctions'
            && $e->broadcastWith()['id'] === $draft->id
            && $e->broadcastWith()['status'] === 'SCHEDULED');
    }

    public function test_creating_an_auction_as_scheduled_announces_it_but_a_draft_does_not(): void
    {
        $admin = User::factory()->admin()->create();
        $data = Auction::factory()->make()->only([
            'title', 'description', 'starting_price', 'reserve_price', 'min_increment', 'start_at', 'end_at',
            'anti_snipe_seconds', 'extension_seconds', 'max_extensions',
        ]);
        $service = app(AuctionLifecycleService::class);

        $service->create($data, $admin);
        Event::assertNotDispatched(AuctionUpdated::class);

        $scheduled = $service->create($data, $admin, schedule: true);
        Event::assertDispatched(AuctionUpdated::class, fn (AuctionUpdated $e) => $e->broadcastWith()['id'] === $scheduled->id);
    }

    public function test_start_and_close_are_announced_and_the_winner_is_told_privately(): void
    {
        $auction = Auction::factory()->scheduled()->create(['reserve_price' => null, 'anti_snipe_seconds' => 0, 'extension_seconds' => 0, 'max_extensions' => 0]);
        $this->travelTo($auction->start_at);
        app(AuctionLifecycleService::class)->start($auction->id);
        $winner = User::factory()->create();
        $this->bids->placeBid($auction, $winner, $auction->starting_price);

        $this->travelTo($auction->end_at);
        app(AuctionCloser::class)->close($auction->id);

        Event::assertDispatched(AuctionStarted::class, fn ($e) => $e->broadcastWith()['status'] === 'LIVE');
        Event::assertDispatched(AuctionEnded::class, fn ($e) => $e->broadcastWith()['result'] === 'SOLD'
            && $e->broadcastWith()['winner'] === 'Bidder 1'
            && $e->broadcastWith()['final_price'] === $auction->starting_price);
        Event::assertDispatched(AuctionWon::class, fn ($e) => $e->userId === $winner->id);

        $sequences = array_map(fn ($e) => $e->sequence, $this->auctionEvents());
        $this->assertSame(range(1, count($sequences)), $sequences, 'Sequence numbers are gap-free and increasing.');
    }

    public function test_reserve_not_met_announces_no_winner(): void
    {
        $auction = $this->liveAuction(['starting_price' => 40_000, 'reserve_price' => 50_000]);
        $this->bids->placeBid($auction, User::factory()->create(), 47_000);
        $this->travelTo($auction->end_at);

        app(AuctionCloser::class)->close($auction->id);

        Event::assertDispatched(AuctionEnded::class, fn ($e) => $e->broadcastWith()['result'] === 'RESERVE_NOT_MET'
            && $e->broadcastWith()['winner'] === null);
        Event::assertNotDispatched(AuctionWon::class);
    }

    public function test_cancellation_is_announced(): void
    {
        $auction = $this->liveAuction();

        app(AuctionLifecycleService::class)->cancel($auction, User::factory()->admin()->create(), 'Withdrawn');

        Event::assertDispatched(AuctionCancelled::class, fn ($e) => $e->broadcastWith()['status'] === 'CANCELLED');
    }

    public function test_rest_snapshot_tells_clients_their_pseudonym_and_last_sequence(): void
    {
        $auction = $this->liveAuction();
        $me = User::factory()->create();
        $this->bids->placeBid($auction, User::factory()->create(), 10_000);
        $this->bids->placeBid($auction, $me, 11_000);

        $this->actingAs($me)->getJson("/api/auctions/{$auction->id}")
            ->assertJsonPath('data.my_pseudonym', 'Bidder 2')
            ->assertJsonPath('data.leader', 'You')
            ->assertJsonPath('data.sequence', $auction->fresh()->event_sequence);

        $this->actingAs(User::factory()->create())->getJson("/api/auctions/{$auction->id}")
            ->assertJsonPath('data.my_pseudonym', null);
    }

    public function test_every_broadcast_event_is_sent_only_after_commit(): void
    {
        foreach ([...self::PUBLIC_EVENTS, ...self::PRIVATE_EVENTS] as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldDispatchAfterCommit::class), "{$class} must wait for commit.");
        }
        $this->assertTrue(is_subclass_of(Outbid::class, UserNotification::class));
    }
}
