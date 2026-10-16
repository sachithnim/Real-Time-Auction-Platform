<?php

namespace Tests\Feature\History;

use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\User;
use App\Services\AuctionLifecycleService;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminAuditLogApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $a;

    private User $b;

    private User $c;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->admin = User::factory()->admin()->create();
        [$this->a, $this->b, $this->c] = User::factory()->count(3)->create()->all();
    }

    /** Drives one auction through its whole life using the real API and services. */
    private function runFullAuction(): Auction
    {
        $this->travelTo(Carbon::parse('2030-05-01 09:00:00'));

        $id = $this->actingAs($this->admin)->postJson('/api/admin/auctions', [
            'title' => 'Vintage Ceylon Tea Chest',
            'description' => 'Teak, circa 1920.',
            'starting_price' => 10_000,
            'reserve_price' => 20_000,
            'min_increment' => 500,
            'start_at' => '2030-05-01T10:00:00Z',
            'end_at' => '2030-05-01T12:00:00Z',
            'anti_snipe_seconds' => 120,
            'extension_seconds' => 120,
            'max_extensions' => 3,
            'status' => 'SCHEDULED',
        ])->assertCreated()->json('data.id');
        $auction = Auction::findOrFail($id);

        $this->travelTo(Carbon::parse('2030-05-01 10:00:00'));
        app(AuctionLifecycleService::class)->start($id);

        $bids = app(BidService::class);
        $this->travelTo(Carbon::parse('2030-05-01 10:30:00'));
        $bids->configureAutoBid($auction, $this->a, 20_000);
        try {
            $bids->placeBid($auction, $this->b, 100); // rejected: too low
        } catch (ApiException) {
        }
        $bids->placeBid($auction, $this->b, 12_000); // answered automatically by A

        $this->travelTo(Carbon::parse('2030-05-01 11:59:30')); // inside the anti-sniping window
        $bids->placeBid($auction, $this->c, 25_000);

        $this->travelTo(Carbon::parse('2030-05-01 12:02:00')); // the extended end
        app(AuctionCloser::class)->close($id);

        return $auction->fresh();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(Auction $auction, string $query = ''): array
    {
        return $this->actingAs($this->admin)
            ->getJson("/api/admin/auctions/{$auction->id}/events?per_page=200{$query}")
            ->assertOk()
            ->json('data');
    }

    public function test_admin_sees_the_audit_trail_in_the_order_it_happened(): void
    {
        $auction = $this->runFullAuction();

        $this->assertSame([
            'AUCTION_CREATED', 'AUCTION_SCHEDULED', 'AUCTION_STARTED',
            'AUTO_BID_CONFIGURED', 'AUTO_BID_PLACED', 'LEADER_CHANGED',
            'BID_REJECTED',
            'BID_PLACED', 'AUTO_BID_PLACED',
            'BID_PLACED', 'LEADER_CHANGED', 'AUCTION_EXTENDED', 'AUTO_BID_MAX_REACHED',
            'AUCTION_ENDED', 'WINNER_SELECTED',
        ], array_column($this->events($auction), 'type'));
    }

    public function test_events_carry_actor_details_or_system(): void
    {
        $events = collect($this->events($this->runFullAuction()));

        $created = $events->firstWhere('type', 'AUCTION_CREATED');
        $this->assertSame($this->admin->email, $created['actor']['email']);
        $this->assertSame('ADMIN', $created['actor']['role']);

        $this->assertNull($events->firstWhere('type', 'AUCTION_STARTED')['actor'], 'Starting is a system action.');
        $this->assertSame('BID_TOO_LOW', $events->firstWhere('type', 'BID_REJECTED')['payload']['reason']);
    }

    public function test_users_named_in_payloads_are_resolved_for_the_admin(): void
    {
        $auction = $this->runFullAuction();

        $users = $this->actingAs($this->admin)->getJson("/api/admin/auctions/{$auction->id}/events?per_page=200")
            ->assertOk()
            ->json('meta.users');

        $this->assertSame(['name' => $this->a->name, 'email' => $this->a->email], $users[(string) $this->a->id]);
        $this->assertArrayHasKey((string) $this->b->id, $users);
    }

    public function test_the_events_alone_reconstruct_the_final_state(): void
    {
        $auction = $this->runFullAuction();
        $events = $this->events($auction);

        $price = null;
        $leader = null;
        $endAt = '2030-05-01T12:00:00Z';
        $result = null;
        foreach ($events as $event) {
            $payload = $event['payload'];
            match ($event['type']) {
                'BID_PLACED', 'AUTO_BID_PLACED' => $price = $payload['new_price'],
                'LEADER_CHANGED' => $leader = $payload['new_leader_id'],
                'AUCTION_EXTENDED' => $endAt = $payload['new_end_at'],
                'AUCTION_ENDED' => $result = $payload['result'],
                default => null,
            };
        }

        $this->assertSame($auction->final_price, $price);
        $this->assertSame($auction->winner_id, $leader);
        $this->assertSame($auction->end_at->utc()->format('Y-m-d\TH:i:s\Z'), $endAt);
        $this->assertSame($auction->result->value, $result);
        $this->assertSame([25_000, $this->c->id, 'SOLD'], [$price, $leader, $result]);
    }

    public function test_every_required_event_type_is_recorded(): void
    {
        $this->runFullAuction();

        // Reserve not met.
        $notMet = Auction::factory()->live()->create(['starting_price' => 40_000, 'reserve_price' => 50_000, 'anti_snipe_seconds' => 0, 'extension_seconds' => 0, 'max_extensions' => 0]);
        app(BidService::class)->placeBid($notMet, $this->a, 47_000);
        $this->travelTo($notMet->end_at);
        app(AuctionCloser::class)->close($notMet->id);

        // Cancelled.
        $cancelled = Auction::factory()->create();
        $this->actingAs($this->admin)->postJson("/api/admin/auctions/{$cancelled->id}/cancel")->assertOk();

        $recorded = collect(Auction::all())->flatMap(fn (Auction $auction) => array_column($this->events($auction), 'type'))->unique();

        foreach ([
            'AUCTION_CREATED', 'AUCTION_SCHEDULED', 'AUCTION_STARTED', 'BID_PLACED', 'BID_REJECTED',
            'AUTO_BID_PLACED', 'AUTO_BID_CONFIGURED', 'LEADER_CHANGED', 'AUCTION_EXTENDED', 'AUCTION_ENDED',
            'WINNER_SELECTED', 'RESERVE_NOT_MET', 'AUCTION_CANCELLED',
        ] as $type) {
            $this->assertContains($type, $recorded, "{$type} was never recorded.");
        }
    }

    public function test_events_can_be_filtered_by_type(): void
    {
        $auction = $this->runFullAuction();

        $placed = $this->events($auction, '&type=BID_PLACED');

        $this->assertSame([12_000, 25_000], array_column(array_column($placed, 'payload'), 'amount'));
    }

    public function test_admin_bid_history_shows_identities_and_pseudonyms(): void
    {
        $auction = $this->runFullAuction();

        $rows = $this->actingAs($this->admin)->getJson("/api/admin/auctions/{$auction->id}/bids")->assertOk()->json('data');

        $this->assertSame($this->c->email, $rows[0]['bidder']['email']);
        $this->assertSame('Bidder 3', $rows[0]['bidder']['pseudonym']);
        $this->assertSame($this->a->id, end($rows)['bidder']['id']);
    }

    public function test_bidders_cannot_read_the_audit_log_or_identities(): void
    {
        $auction = Auction::factory()->live()->create();

        // Guest first: actingAs() keeps the user signed in for the rest of the test.
        $this->getJson("/api/admin/auctions/{$auction->id}/events")->assertUnauthorized();
        $this->actingAs($this->a)->getJson("/api/admin/auctions/{$auction->id}/events")->assertForbidden();
        $this->actingAs($this->a)->getJson("/api/admin/auctions/{$auction->id}/bids")->assertForbidden();
    }
}
