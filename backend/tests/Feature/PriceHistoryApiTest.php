<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\User;
use App\Services\Bidding\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_every_bid_oldest_first_naming_no_one(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 10_000, 'reserve_price' => null]);
        $asha = User::factory()->create(['name' => 'Asha Perera', 'email' => 'asha@example.com']);
        $ravi = User::factory()->create();
        $bids = app(BidService::class);
        $bids->placeBid($auction, $asha, 10_000);
        $this->travel(1)->minutes();
        $bids->configureAutoBid($auction->fresh(), $ravi, 15_000);

        $response = $this->actingAs($asha)->getJson("/api/auctions/{$auction->id}/price-history")
            ->assertOk()
            ->assertJsonPath('data.starting_price', 10_000)
            ->assertJsonCount(2, 'data.points');

        $points = $response->json('data.points');
        $this->assertSame([10_000, 10_100], array_column($points, 'amount'));
        $this->assertSame(['MANUAL', 'AUTO'], array_column($points, 'type'));
        $this->assertSame([true, false], array_column($points, 'mine'));
        $this->assertLessThan($points[1]['at'], $points[0]['at']);
        $this->assertStringNotContainsString('asha', strtolower($response->getContent()));
        $this->assertStringNotContainsString('15000', $response->getContent());
    }

    public function test_guests_see_it_too_with_nothing_marked_as_theirs(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 10_000]);
        app(BidService::class)->placeBid($auction, User::factory()->create(), 10_000);

        $this->getJson("/api/auctions/{$auction->id}/price-history")
            ->assertOk()
            ->assertJsonPath('data.points.0.mine', false);
    }

    public function test_drafts_are_not_found(): void
    {
        $this->getJson('/api/auctions/'.Auction::factory()->create()->id.'/price-history')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
    }
}
