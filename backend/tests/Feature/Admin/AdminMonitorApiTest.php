<?php

namespace Tests\Feature\Admin;

use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\User;
use App\Services\Bidding\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMonitorApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private BidService $bids;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->bids = app(BidService::class);
    }

    private function monitor(?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->getJson('/api/admin/monitor');
    }

    private function liveAuction(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create([
            'starting_price' => 10_000, 'reserve_price' => 50_000, 'min_increment' => 500, ...$attributes,
        ]);
    }

    private function rejectedBid(Auction $auction, User $bidder, int $amount): void
    {
        try {
            $this->bids->placeBid($auction, $bidder, $amount);
            $this->fail('The bid should have been rejected.');
        } catch (ApiException) {
        }
    }

    public function test_admin_sees_every_live_auction_with_leader_identity_and_reserve(): void
    {
        $auction = $this->liveAuction(['title' => 'Kandyan Mask']);
        $a = User::factory()->create();
        $b = User::factory()->create(['name' => 'Asha Perera', 'email' => 'asha@example.com']);
        $this->bids->placeBid($auction, $a, 10_000);
        $this->bids->placeBid($auction, $b, 11_000);

        Auction::factory()->scheduled()->create();
        Auction::factory()->completed()->create();
        Auction::factory()->create();

        $this->monitor()
            ->assertOk()
            ->assertJsonStructure(['meta' => ['server_time']])
            ->assertJsonCount(1, 'data.auctions')
            ->assertJsonPath('data.auctions.0.id', $auction->id)
            ->assertJsonPath('data.auctions.0.title', 'Kandyan Mask')
            ->assertJsonPath('data.auctions.0.status', 'LIVE')
            ->assertJsonPath('data.auctions.0.current_price', 11_000)
            ->assertJsonPath('data.auctions.0.reserve_price', 50_000)
            ->assertJsonPath('data.auctions.0.reserve_met', false)
            ->assertJsonPath('data.auctions.0.min_next_bid', 11_500)
            ->assertJsonPath('data.auctions.0.leader', [
                'id' => $b->id, 'name' => 'Asha Perera', 'email' => 'asha@example.com', 'pseudonym' => 'Bidder 2',
            ])
            ->assertJsonPath('data.auctions.0.bids_count', 2)
            ->assertJsonPath('data.auctions.0.recent_bids', 2)
            ->assertJsonPath('data.auctions.0.active_auto_bids', 0)
            ->assertJsonPath('data.auctions.0.sequence', $auction->fresh()->event_sequence);
    }

    public function test_recent_bids_count_only_the_last_five_minutes(): void
    {
        $auction = $this->liveAuction();
        $this->bids->placeBid($auction, User::factory()->create(), 10_000);
        $this->travel(6)->minutes();
        $this->bids->placeBid($auction, User::factory()->create(), 11_000);
        $this->bids->configureAutoBid($auction, User::factory()->create(), 30_000);

        $this->monitor()
            ->assertJsonPath('data.auctions.0.bids_count', 3)
            ->assertJsonPath('data.auctions.0.recent_bids', 2)
            ->assertJsonPath('data.auctions.0.active_auto_bids', 1);
    }

    public function test_live_auctions_are_listed_ending_soonest_first(): void
    {
        $later = $this->liveAuction(['end_at' => now()->addHours(3), 'original_end_at' => now()->addHours(3)]);
        $sooner = $this->liveAuction(['end_at' => now()->addMinutes(10), 'original_end_at' => now()->addMinutes(10)]);

        $ids = array_column($this->monitor()->json('data.auctions'), 'id');

        $this->assertSame([$sooner->id, $later->id], $ids);
    }

    public function test_recent_rejections_are_newest_first_with_the_bidder_and_reason(): void
    {
        $auction = $this->liveAuction(['title' => 'Tea Chest']);
        $bidder = User::factory()->create(['name' => 'Nimal Silva', 'email' => 'nimal@example.com']);
        $this->bids->placeBid($auction, User::factory()->create(), 10_000);

        foreach (range(1, 12) as $i) {
            $this->rejectedBid($auction, $bidder, 10_000 + $i);
        }

        $rejections = $this->monitor()->assertOk()->json('data.recent_rejections');

        $this->assertCount(10, $rejections);
        $this->assertSame(10_012, $rejections[0]['amount'], 'Newest first.');
        $this->assertSame(10_003, $rejections[9]['amount']);
        $this->assertSame('BID_TOO_LOW', $rejections[0]['reason']);
        $this->assertSame('BID', $rejections[0]['action']);
        $this->assertSame('Tea Chest', $rejections[0]['auction_title']);
        $this->assertSame(['id' => $bidder->id, 'name' => 'Nimal Silva', 'email' => 'nimal@example.com'], $rejections[0]['bidder']);
    }

    public function test_an_empty_platform_shows_nothing_live(): void
    {
        $this->monitor()
            ->assertOk()
            ->assertJsonPath('data.auctions', [])
            ->assertJsonPath('data.recent_rejections', []);
    }

    public function test_bidders_cannot_open_the_monitor(): void
    {
        $this->monitor(User::factory()->create())->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_guests_cannot_open_the_monitor(): void
    {
        $this->getJson('/api/admin/monitor')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
