<?php

namespace Tests\Feature\Models;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Enums\BidType;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use Tests\TestCase;

class DomainModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_build_valid_auctions_in_every_state(): void
    {
        $this->assertSame(AuctionStatus::Draft, Auction::factory()->create()->status);
        $this->assertSame(AuctionStatus::Scheduled, Auction::factory()->scheduled()->create()->status);
        $this->assertSame(AuctionStatus::Live, Auction::factory()->live()->create()->status);
        $this->assertSame(AuctionStatus::Completed, Auction::factory()->completed()->create()->status);
        $this->assertSame(AuctionStatus::Cancelled, Auction::factory()->cancelled()->create()->status);
    }

    public function test_money_is_cast_to_integers(): void
    {
        $auction = Auction::factory()->create(['starting_price' => 25_000])->fresh();

        $this->assertSame(25_000, $auction->starting_price);
        $this->assertSame(100, $auction->min_increment);
    }

    public function test_status_cannot_be_mass_assigned(): void
    {
        $auction = Auction::factory()->create();

        $auction->update(['status' => 'LIVE', 'title' => 'Renamed']);

        $this->assertSame(AuctionStatus::Draft, $auction->fresh()->status);
        $this->assertSame('Renamed', $auction->fresh()->title);
    }

    public function test_reserve_price_is_hidden_from_default_serialization(): void
    {
        $auction = Auction::factory()->create(['reserve_price' => 50_000]);

        $this->assertArrayNotHasKey('reserve_price', $auction->toArray());
        $this->assertSame(50_000, $auction->reserve_price);
    }

    public function test_auto_bid_maximum_is_hidden_from_default_serialization(): void
    {
        $autoBid = AutoBid::factory()->create(['max_amount' => 20_000]);

        $this->assertArrayNotHasKey('max_amount', $autoBid->toArray());
    }

    public function test_bids_are_immutable(): void
    {
        $bid = Bid::factory()->create(['amount' => 10_000]);

        try {
            $bid->update(['amount' => 1]);
            $this->fail('A bid was updated.');
        } catch (LogicException) {
        }

        try {
            $bid->delete();
            $this->fail('A bid was deleted.');
        } catch (LogicException) {
        }

        $this->assertSame(10_000, $bid->fresh()->amount);
    }

    public function test_audit_events_are_immutable(): void
    {
        $event = AuctionEvent::create([
            'auction_id' => Auction::factory()->create()->id,
            'type' => AuctionEventType::AuctionCreated,
            'payload' => ['title' => 'Vintage watch'],
        ]);

        $this->expectException(LogicException::class);

        $event->delete();
    }

    public function test_bid_history_is_ordered_by_id_and_keeps_microseconds(): void
    {
        $auction = Auction::factory()->live()->create();
        $bidder = User::factory()->create();

        $first = Bid::factory()->for($auction)->for($bidder)->create(['amount' => 30_000]);
        $second = Bid::factory()->for($auction)->for($bidder)->auto()->create(['amount' => 30_500]);

        $this->assertSame([$first->id, $second->id], $auction->bids()->pluck('id')->all());
        $this->assertSame(BidType::Auto, $auction->bids->last()->type);
        $this->assertNotSame('000000', $first->fresh()->created_at->format('u'));
    }

    public function test_missing_auction_returns_auction_not_found(): void
    {
        Route::middleware('api')->get('/api/_test/auctions/{auction}', fn (Auction $auction) => $auction);

        $this->getJson('/api/_test/auctions/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
    }
}
