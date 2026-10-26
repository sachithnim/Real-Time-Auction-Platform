<?php

namespace Tests\Feature\Admin;

use App\Enums\AuctionResult;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuctionStatisticsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function statistics(?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->getJson('/api/admin/statistics');
    }

    private function bids(Auction $auction, User $bidder, int $count, bool $auto = false): void
    {
        $factory = Bid::factory()->for($auction)->for($bidder)->count($count);
        ($auto ? $factory->auto() : $factory)->create();
    }

    /**
     * A known mix: one auction in every status, three completed outcomes and
     * three bidders, so every figure can be checked by hand.
     *
     * @return array{a: User, b: User, c: User}
     */
    private function seedPlatform(): array
    {
        [$a, $b, $c] = User::factory()->count(3)->create()->all();
        $completed = fn (AuctionResult $result, array $attributes) => Auction::factory()
            ->completed($result)
            ->create(['starting_price' => 1_000, ...$attributes]);

        Auction::factory()->create();                // DRAFT
        Auction::factory()->scheduled()->create();   // SCHEDULED
        Auction::factory()->cancelled()->create();   // CANCELLED

        $live = Auction::factory()->live()->create();
        $this->bids($live, $a, 2);
        $this->bids($live, $b, 2, auto: true);

        $soldToA = $completed(AuctionResult::Sold, [
            'current_price' => 30_000, 'final_price' => 30_000, 'leader_id' => $a->id, 'winner_id' => $a->id, 'extensions_count' => 2,
        ]);
        $this->bids($soldToA, $a, 1);
        $this->bids($soldToA, $b, 1);

        $soldToB = $completed(AuctionResult::Sold, [
            'current_price' => 50_000, 'final_price' => 50_000, 'leader_id' => $b->id, 'winner_id' => $b->id,
        ]);
        $this->bids($soldToB, $b, 1);

        $reserveNotMet = $completed(AuctionResult::ReserveNotMet, [
            'reserve_price' => 100_000, 'current_price' => 47_000, 'leader_id' => $c->id, 'extensions_count' => 1,
        ]);
        $this->bids($reserveNotMet, $c, 1);

        $completed(AuctionResult::NoBids, []);

        return compact('a', 'b', 'c');
    }

    public function test_admin_sees_figures_computed_from_the_platform(): void
    {
        ['a' => $a, 'b' => $b, 'c' => $c] = $this->seedPlatform();

        $this->statistics()
            ->assertOk()
            ->assertJsonStructure(['meta' => ['server_time']])
            ->assertJsonPath('data.auctions', [
                'total' => 8,
                'by_status' => ['DRAFT' => 1, 'SCHEDULED' => 1, 'LIVE' => 1, 'COMPLETED' => 4, 'CANCELLED' => 1],
            ])
            ->assertJsonPath('data.results', [
                'completed' => 4, 'sold' => 2, 'reserve_not_met' => 1, 'no_bids' => 1, 'sell_through_percent' => 50,
            ])
            ->assertJsonPath('data.revenue', ['total' => 80_000, 'average' => 40_000, 'highest' => 50_000])
            ->assertJsonPath('data.bids', [
                'total' => 8, 'manual' => 6, 'auto' => 2, 'bidders' => 3, 'auctions_with_bids' => 4, 'average_per_auction' => 2,
            ])
            ->assertJsonPath('data.anti_sniping', ['extended_auctions' => 2, 'total_extensions' => 3])
            ->assertJsonPath('data.top_bidders', [
                ['id' => $b->id, 'name' => $b->name, 'email' => $b->email, 'bids' => 4, 'auctions' => 3, 'wins' => 1],
                ['id' => $a->id, 'name' => $a->name, 'email' => $a->email, 'bids' => 3, 'auctions' => 2, 'wins' => 1],
                ['id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'bids' => 1, 'auctions' => 1, 'wins' => 0],
            ]);
    }

    public function test_a_platform_without_activity_reports_zeros_and_no_averages(): void
    {
        $this->statistics()
            ->assertOk()
            ->assertJsonPath('data.auctions.total', 0)
            ->assertJsonPath('data.results.sell_through_percent', null)
            ->assertJsonPath('data.revenue', ['total' => 0, 'average' => null, 'highest' => null])
            ->assertJsonPath('data.bids.total', 0)
            ->assertJsonPath('data.bids.average_per_auction', null)
            ->assertJsonPath('data.anti_sniping', ['extended_auctions' => 0, 'total_extensions' => 0])
            ->assertJsonPath('data.top_bidders', []);
    }

    public function test_sell_through_and_averages_are_rounded(): void
    {
        $bidder = User::factory()->create();
        foreach ([10_000, 10_001] as $price) {
            Auction::factory()->completed(AuctionResult::Sold)->create([
                'starting_price' => 1_000, 'current_price' => $price, 'final_price' => $price,
                'leader_id' => $bidder->id, 'winner_id' => $bidder->id,
            ]);
        }
        Auction::factory()->completed(AuctionResult::NoBids)->create();

        // 2 of 3 sold = 66.666…%; the average of 10,000 and 10,001 is 10,000.5 rupees.
        $this->statistics()
            ->assertJsonPath('data.results.sell_through_percent', 66.7)
            ->assertJsonPath('data.revenue.average', 10_001);
    }

    public function test_top_bidders_are_limited_and_ties_keep_account_order(): void
    {
        $auction = Auction::factory()->live()->create();
        $bidders = User::factory()->count(6)->create();
        $bidders->each(fn (User $bidder) => $this->bids($auction, $bidder, 1));

        $top = $this->statistics()->assertOk()->json('data.top_bidders');

        $this->assertCount(5, $top);
        $this->assertSame($bidders->take(5)->pluck('id')->all(), array_column($top, 'id'));
    }

    public function test_bidders_cannot_see_statistics(): void
    {
        $this->statistics(User::factory()->create())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_guests_cannot_see_statistics(): void
    {
        $this->getJson('/api/admin/statistics')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
