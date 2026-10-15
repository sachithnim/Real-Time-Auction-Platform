<?php

namespace Tests\Feature\Bidding;

use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Services\Bidding\BidderPseudonyms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BidderPseudonymsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bidders_are_numbered_by_their_first_bid_in_the_auction(): void
    {
        $auction = Auction::factory()->live()->create();
        [$first, $second, $third] = User::factory()->count(3)->create()->all();
        foreach ([[$second, 1_000], [$first, 1_100], [$second, 1_200], [$third, 1_300]] as [$user, $amount]) {
            Bid::factory()->for($auction)->for($user)->create(['amount' => $amount]);
        }

        $names = app(BidderPseudonyms::class);

        $this->assertSame('Bidder 1', $names->nameFor($auction, $second->id, null));
        $this->assertSame('Bidder 2', $names->nameFor($auction, $first->id, null));
        $this->assertSame('Bidder 3', $names->nameFor($auction, $third->id, null));
    }

    public function test_the_viewer_sees_you_for_themselves(): void
    {
        $auction = Auction::factory()->live()->create();
        $me = User::factory()->create();
        Bid::factory()->for($auction)->for($me)->create();

        $this->assertSame('You', app(BidderPseudonyms::class)->nameFor($auction, $me->id, $me));
    }

    public function test_numbers_are_independent_per_auction(): void
    {
        $user = User::factory()->create();
        $auctionA = Auction::factory()->live()->create();
        $auctionB = Auction::factory()->live()->create();
        Bid::factory()->for($auctionA)->for($user)->create();
        Bid::factory()->for($auctionB)->for(User::factory()->create())->create();
        Bid::factory()->for($auctionB)->for($user)->create();

        $names = app(BidderPseudonyms::class);

        $this->assertSame('Bidder 1', $names->nameFor($auctionA, $user->id, null));
        $this->assertSame('Bidder 2', $names->nameFor($auctionB, $user->id, null));
    }

    public function test_no_name_without_a_user(): void
    {
        $this->assertNull(app(BidderPseudonyms::class)->nameFor(Auction::factory()->create(), null, null));
    }
}
