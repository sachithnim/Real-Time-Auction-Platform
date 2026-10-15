<?php

namespace Tests\Feature\Bidding;

use App\Enums\AuctionEventType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\User;
use App\Services\Bidding\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AntiSnipingTest extends TestCase
{
    use RefreshDatabase;

    private BidService $bids;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bids = app(BidService::class);
    }

    /** The assignment's configuration: ends 20:00, 2-minute window, 2-minute extension, at most 3 extensions. */
    private function assignmentAuction(array $overrides = []): Auction
    {
        $this->travelTo(Carbon::parse('2030-03-01 18:30:00'));

        return Auction::factory()->live()->create(array_merge([
            'starting_price' => 10_000,
            'reserve_price' => null,
            'min_increment' => 500,
            'start_at' => '2030-03-01 18:00:00',
            'end_at' => '2030-03-01 20:00:00',
            'original_end_at' => '2030-03-01 20:00:00',
            'anti_snipe_seconds' => 120,
            'extension_seconds' => 120,
            'max_extensions' => 3,
        ], $overrides));
    }

    private function bidAt(Auction $auction, string $time, int $amount): void
    {
        $this->travelTo(Carbon::parse("2030-03-01 {$time}"));
        $this->bids->placeBid($auction, User::factory()->create(), $amount);
    }

    private function endOf(Auction $auction): string
    {
        return $auction->fresh()->end_at->format('H:i:s');
    }

    public function test_assignment_example_bid_at_195920_extends_the_auction(): void
    {
        $auction = $this->assignmentAuction();

        $this->bidAt($auction, '19:59:20', 10_000);

        $this->assertSame('20:02:00', $this->endOf($auction));
        $this->assertSame(1, $auction->fresh()->extensions_count);
        $this->assertSame('20:00:00', $auction->fresh()->original_end_at->format('H:i:s'));

        $event = AuctionEvent::where('type', AuctionEventType::AuctionExtended)->sole();
        $this->assertSame('2030-03-01T20:00:00Z', $event->payload['previous_end_at']);
        $this->assertSame('2030-03-01T20:02:00Z', $event->payload['new_end_at']);
        $this->assertSame(1, $event->payload['extensions_count']);
    }

    public function test_bid_before_the_window_does_not_extend(): void
    {
        $auction = $this->assignmentAuction();

        $this->bidAt($auction, '19:57:59', 10_000);

        $this->assertSame('20:00:00', $this->endOf($auction));
        $this->assertSame(0, AuctionEvent::where('type', AuctionEventType::AuctionExtended)->count());
    }

    public function test_bid_exactly_when_the_window_opens_extends(): void
    {
        $auction = $this->assignmentAuction();

        $this->bidAt($auction, '19:58:00', 10_000);

        $this->assertSame('20:02:00', $this->endOf($auction));
    }

    public function test_extended_auction_accepts_bids_after_the_original_end(): void
    {
        $auction = $this->assignmentAuction();
        $this->bidAt($auction, '19:59:20', 10_000);

        // 20:01:00 is after the original 20:00 end but before the new 20:02 end, and inside the new window.
        $this->bidAt($auction, '20:01:00', 10_500);

        $this->assertSame(10_500, $auction->fresh()->current_price);
        $this->assertSame('20:04:00', $this->endOf($auction));
    }

    public function test_maximum_extensions_are_respected_and_later_bids_still_count(): void
    {
        $auction = $this->assignmentAuction();

        $this->bidAt($auction, '19:59:00', 10_000); // → 20:02
        $this->bidAt($auction, '20:01:00', 10_500); // → 20:04
        $this->bidAt($auction, '20:03:00', 11_000); // → 20:06 (3rd and last extension)
        $this->bidAt($auction, '20:05:30', 11_500); // 4th qualifying bid: accepted, no extension

        $fresh = $auction->fresh();
        $this->assertSame('20:06:00', $fresh->end_at->format('H:i:s'));
        $this->assertSame(3, $fresh->extensions_count);
        $this->assertSame(11_500, $fresh->current_price);
        $this->assertSame(3, AuctionEvent::where('type', AuctionEventType::AuctionExtended)->count());
    }

    public function test_bids_after_the_extended_end_are_rejected(): void
    {
        $auction = $this->assignmentAuction();
        $this->bidAt($auction, '19:59:20', 10_000);

        $this->travelTo(Carbon::parse('2030-03-01 20:02:00'));

        try {
            $this->bids->placeBid($auction, User::factory()->create(), 10_500);
            $this->fail('A bid at the extended end time must be rejected.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AuctionEnded, $e->errorCode);
        }
    }

    public function test_anti_sniping_can_be_disabled(): void
    {
        $auction = $this->assignmentAuction(['anti_snipe_seconds' => 0, 'extension_seconds' => 0, 'max_extensions' => 0]);

        $this->bidAt($auction, '19:59:50', 10_000);

        $this->assertSame('20:00:00', $this->endOf($auction));
    }

    public function test_an_auto_bid_that_places_bids_in_the_window_extends(): void
    {
        $auction = $this->assignmentAuction();
        $this->travelTo(Carbon::parse('2030-03-01 19:59:30'));

        $this->bids->configureAutoBid($auction, User::factory()->create(), 20_000);

        $this->assertSame('20:02:00', $this->endOf($auction));
    }

    public function test_leader_raising_their_maximum_does_not_extend(): void
    {
        $auction = $this->assignmentAuction();
        $leader = User::factory()->create();
        $this->travelTo(Carbon::parse('2030-03-01 19:00:00'));
        $this->bids->configureAutoBid($auction, $leader, 20_000);

        $this->travelTo(Carbon::parse('2030-03-01 19:59:30'));
        $this->bids->configureAutoBid($auction, $leader, 30_000);

        $this->assertSame('20:00:00', $this->endOf($auction), 'No visible bid was placed, so nothing was sniped.');
    }

    public function test_a_rejected_bid_in_the_window_does_not_extend(): void
    {
        $auction = $this->assignmentAuction();
        $this->travelTo(Carbon::parse('2030-03-01 19:59:30'));

        try {
            $this->bids->placeBid($auction, User::factory()->create(), 100);
        } catch (ApiException) {
        }

        $this->assertSame('20:00:00', $this->endOf($auction));
    }
}
