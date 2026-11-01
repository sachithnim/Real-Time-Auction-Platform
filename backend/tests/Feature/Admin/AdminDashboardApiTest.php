<?php

namespace Tests\Feature\Admin;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create(['created_at' => now()->subWeek()]);
    }

    private function dashboard(?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin)->getJson('/api/admin/dashboard');
    }

    private function flag(Auction $auction, User $bidder, string $status): void
    {
        DB::table('bid_flags')->insert([
            'dedupe_key' => uniqid('flag', true), 'auction_id' => $auction->id, 'rule' => 'SHARED_IP', 'user_id' => $bidder->id,
            'details' => '{}', 'status' => $status, 'first_detected_at' => now(), 'last_detected_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_it_counts_auctions_by_status(): void
    {
        Auction::factory()->count(2)->create();
        Auction::factory()->scheduled()->count(3)->create();
        Auction::factory()->live()->create();
        Auction::factory()->completed()->create();

        $this->dashboard()->assertOk()->assertJsonPath('data.auctions', ['draft' => 2, 'scheduled' => 3, 'live' => 1]);
    }

    public function test_the_last_24_hours_leave_out_older_activity(): void
    {
        $live = Auction::factory()->live()->create();
        $asha = User::factory()->create(['created_at' => now()->subHour()]);
        $nimal = User::factory()->create(['created_at' => now()->subDays(3)]);
        Bid::factory()->for($live)->for($asha)->count(2)->create(['created_at' => now()->subHours(2)]);
        Bid::factory()->for($live)->for($nimal)->create(['created_at' => now()->subMinutes(5)]);
        Bid::factory()->for($live)->for($nimal)->create(['created_at' => now()->subDays(2)]);

        Auction::factory()->completed(AuctionResult::Sold)->create(['final_price' => 40_000, 'ended_at' => now()->subHours(3)]);
        Auction::factory()->completed(AuctionResult::Sold)->create(['final_price' => 99_000, 'ended_at' => now()->subDays(2)]);
        Auction::factory()->completed(AuctionResult::ReserveNotMet)->create(['ended_at' => now()->subHour()]);

        $this->dashboard()->assertOk()->assertJsonPath('data.last_24_hours', [
            'bids' => 3,
            'active_bidders' => 2,
            'new_bidders' => 1,
            'auctions_ended' => 2,
            'sold_value' => 40_000,
        ]);
    }

    public function test_it_lists_what_needs_attention(): void
    {
        $sold = Auction::factory()->completed(AuctionResult::Sold)->create(['final_price' => 10_000]);
        $bidder = User::factory()->create();
        $this->flag($sold, $bidder, 'OPEN');
        $this->flag($sold, $bidder, 'OPEN');
        $this->flag($sold, $bidder, 'REVIEWED');

        $this->dashboard()->assertOk()->assertJsonPath('data.attention', ['open_flags' => 2, 'unpaid_wins' => 0, 'unpaid_sold' => 1]);
    }

    public function test_short_lists_are_in_the_most_useful_order(): void
    {
        $later = Auction::factory()->live()->create(['end_at' => now()->addHours(3), 'original_end_at' => now()->addHours(3)]);
        $sooner = Auction::factory()->live()->create(['end_at' => now()->addMinutes(10), 'original_end_at' => now()->addMinutes(10)]);
        $next = Auction::factory()->scheduled()->create(['start_at' => now()->addHour(), 'end_at' => now()->addHours(2), 'original_end_at' => now()->addHours(2)]);
        Auction::factory()->count(6)->create();

        $response = $this->dashboard()->assertOk();

        $this->assertSame([$sooner->id, $later->id], array_column($response->json('data.ending_soon'), 'id'));
        $this->assertSame($next->id, $response->json('data.starting_soon.0.id'));
        $response->assertJsonPath('data.starting_soon.0.images_count', 0)
            ->assertJsonCount(5, 'data.drafts');
    }

    public function test_recent_activity_shows_milestones_newest_first(): void
    {
        $auction = Auction::factory()->live()->create(['title' => 'Brass Telescope']);
        $bidder = User::factory()->create(['name' => 'Asha Perera']);
        AuctionEvent::create(['auction_id' => $auction->id, 'type' => AuctionEventType::AuctionStarted, 'actor_id' => null, 'payload' => []]);
        AuctionEvent::create(['auction_id' => $auction->id, 'type' => AuctionEventType::BidRejected, 'actor_id' => $bidder->id, 'payload' => []]);
        AuctionEvent::create(['auction_id' => $auction->id, 'type' => AuctionEventType::BidPlaced, 'actor_id' => $bidder->id, 'payload' => ['amount' => 25_500]]);

        $this->dashboard()->assertOk()
            ->assertJsonCount(2, 'data.recent_activity')
            ->assertJsonPath('data.recent_activity.0.type', 'BID_PLACED')
            ->assertJsonPath('data.recent_activity.0.actor', 'Asha Perera')
            ->assertJsonPath('data.recent_activity.0.amount', 25_500)
            ->assertJsonPath('data.recent_activity.0.auction.title', 'Brass Telescope')
            ->assertJsonPath('data.recent_activity.1.type', 'AUCTION_STARTED');
    }

    public function test_only_admins_can_see_the_dashboard(): void
    {
        $this->dashboard(User::factory()->create())->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
    }
}
