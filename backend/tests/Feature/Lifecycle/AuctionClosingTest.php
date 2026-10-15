<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Jobs\CloseAuctionJob;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\User;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuctionClosingTest extends TestCase
{
    use RefreshDatabase;

    private BidService $bids;

    private AuctionCloser $closer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bids = app(BidService::class);
        $this->closer = app(AuctionCloser::class);
    }

    private function liveAuction(array $attributes = []): Auction
    {
        return Auction::factory()->live()->create(array_merge([
            'starting_price' => 10_000,
            'reserve_price' => null,
            'min_increment' => 500,
            'anti_snipe_seconds' => 0,
            'extension_seconds' => 0,
            'max_extensions' => 0,
        ], $attributes));
    }

    private function closeAfterEnd(Auction $auction): Auction
    {
        $this->travelTo($auction->fresh()->end_at);
        $this->closer->close($auction->id);

        return $auction->fresh();
    }

    private function eventTypes(): array
    {
        return AuctionEvent::orderBy('id')->pluck('type')->map->value->all();
    }

    // --- Results ------------------------------------------------------------

    public function test_auction_without_bids_ends_with_no_bids(): void
    {
        $closed = $this->closeAfterEnd($this->liveAuction());

        $this->assertSame(AuctionStatus::Completed, $closed->status);
        $this->assertSame(AuctionResult::NoBids, $closed->result);
        $this->assertNull($closed->winner_id);
        $this->assertNull($closed->final_price);
        $this->assertNotNull($closed->ended_at);
        $this->assertSame(['AUCTION_ENDED'], $this->eventTypes());
    }

    public function test_assignment_example_reserve_50000_highest_47000_is_not_sold(): void
    {
        $auction = $this->liveAuction(['starting_price' => 40_000, 'reserve_price' => 50_000]);
        $bidder = User::factory()->create();
        $this->bids->placeBid($auction, $bidder, 47_000);

        $closed = $this->closeAfterEnd($auction);

        $this->assertSame(AuctionResult::ReserveNotMet, $closed->result);
        $this->assertNull($closed->winner_id, 'No winner when the reserve is not met.');
        $this->assertNull($closed->final_price);

        $event = AuctionEvent::where('type', AuctionEventType::ReserveNotMet)->sole();
        $this->assertSame(47_000, $event->payload['highest_bid']);
        $this->assertSame(50_000, $event->payload['reserve_price']);
        $this->assertSame($bidder->id, $event->payload['highest_bidder_id']);
        $this->assertSame(0, AuctionEvent::where('type', AuctionEventType::WinnerSelected)->count());
    }

    public function test_bid_exactly_at_the_reserve_wins(): void
    {
        $auction = $this->liveAuction(['starting_price' => 40_000, 'reserve_price' => 50_000]);
        $bidder = User::factory()->create();
        $this->bids->placeBid($auction, $bidder, 50_000);

        $closed = $this->closeAfterEnd($auction);

        $this->assertSame(AuctionResult::Sold, $closed->result);
        $this->assertSame($bidder->id, $closed->winner_id);
        $this->assertSame(50_000, $closed->final_price);
    }

    public function test_highest_bidder_wins_when_there_is_no_reserve(): void
    {
        $auction = $this->liveAuction();
        $this->bids->placeBid($auction, User::factory()->create(), 10_000);
        $winner = User::factory()->create();
        $this->bids->placeBid($auction, $winner, 12_000);

        $closed = $this->closeAfterEnd($auction);

        $this->assertSame(AuctionResult::Sold, $closed->result);
        $this->assertSame($winner->id, $closed->winner_id);
        $this->assertSame(12_000, $closed->final_price);
        $this->assertSame(['AUCTION_ENDED', 'WINNER_SELECTED'], array_slice($this->eventTypes(), -2));
    }

    public function test_proxy_winner_pays_only_what_was_needed_not_their_maximum(): void
    {
        $auction = $this->liveAuction();
        $a = User::factory()->create();
        $this->bids->configureAutoBid($auction, $a, 20_000);
        $this->travel(1)->seconds();
        $this->bids->configureAutoBid($auction, User::factory()->create(), 15_000);

        $closed = $this->closeAfterEnd($auction);

        $this->assertSame($a->id, $closed->winner_id);
        $this->assertSame(15_500, $closed->final_price);
    }

    public function test_on_equal_bids_the_earlier_commitment_wins(): void
    {
        $auction = $this->liveAuction();
        $a = User::factory()->create();
        $this->bids->configureAutoBid($auction, $a, 20_000);
        $this->travel(1)->seconds();
        $this->bids->placeBid($auction, User::factory()->create(), 20_000);

        $closed = $this->closeAfterEnd($auction);

        $this->assertSame($a->id, $closed->winner_id);
        $this->assertSame(20_000, $closed->final_price);
    }

    public function test_result_is_visible_and_only_the_winner_learns_they_won(): void
    {
        $auction = $this->liveAuction();
        $winner = User::factory()->create();
        $this->bids->placeBid($auction, $winner, 12_000);
        $this->closeAfterEnd($auction);

        $this->actingAs($winner)->getJson("/api/auctions/{$auction->id}")
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.result', 'SOLD')
            ->assertJsonPath('data.final_price', 12_000)
            ->assertJsonPath('data.is_winner', true)
            ->assertJsonMissingPath('data.winner_id');

        $this->actingAs(User::factory()->create())->getJson("/api/auctions/{$auction->id}")
            ->assertJsonPath('data.is_winner', false);
    }

    // --- Timing, idempotency and blocking further bids ----------------------

    public function test_closing_twice_changes_nothing_the_second_time(): void
    {
        $auction = $this->liveAuction();
        $this->bids->placeBid($auction, User::factory()->create(), 10_000);
        $this->travelTo($auction->end_at);

        $first = $this->closer->close($auction->id);
        $second = $this->closer->close($auction->id);

        $this->assertTrue($first->wasClosed());
        $this->assertSame('SKIPPED', $second->status);
        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::AuctionEnded)->count());
        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::WinnerSelected)->count());
    }

    public function test_close_before_the_end_time_does_nothing(): void
    {
        $auction = $this->liveAuction();
        $this->travelTo($auction->end_at->subSecond());

        $outcome = $this->closer->close($auction->id);

        $this->assertTrue($outcome->isNotDue());
        $this->assertSame(AuctionStatus::Live, $auction->fresh()->status);
    }

    public function test_close_respects_an_anti_sniping_extension(): void
    {
        $auction = $this->liveAuction(['anti_snipe_seconds' => 120, 'extension_seconds' => 120, 'max_extensions' => 3]);
        $originalEnd = $auction->end_at;
        $this->travelTo($originalEnd->subSeconds(30));
        $this->bids->placeBid($auction, User::factory()->create(), 10_000);

        // The close was scheduled for the original end; the auction now ends two minutes later.
        $this->travelTo($originalEnd);
        $outcome = $this->closer->close($auction->id);

        $this->assertTrue($outcome->isNotDue());
        $this->assertTrue($outcome->endAt->eq($originalEnd->addSeconds(120)));
        $this->assertSame(AuctionStatus::Live, $auction->fresh()->status);

        $this->travelTo($originalEnd->addSeconds(120));
        $this->assertTrue($this->closer->close($auction->id)->wasClosed());
    }

    public function test_cancelled_auction_is_never_closed(): void
    {
        $auction = Auction::factory()->cancelled()->create();
        $this->travelTo($auction->end_at->addHour());

        $this->assertSame('SKIPPED', $this->closer->close($auction->id)->status);
        $this->assertSame(AuctionStatus::Cancelled, $auction->fresh()->status);
        $this->assertNull($auction->fresh()->result);
    }

    public function test_no_bids_are_accepted_after_closing(): void
    {
        $auction = $this->closeAfterEnd($this->liveAuction());

        try {
            $this->bids->placeBid($auction, User::factory()->create(), 1_000_000);
            $this->fail('A closed auction accepted a bid.');
        } catch (ApiException $e) {
            $this->assertSame(ErrorCode::AuctionEnded, $e->errorCode);
        }
    }

    // --- Job and sweep ------------------------------------------------------

    public function test_job_reschedules_itself_when_the_end_was_extended(): void
    {
        Queue::fake();
        $auction = $this->liveAuction();
        $newEnd = $auction->end_at->addMinutes(2);
        $auction->forceFill(['end_at' => $newEnd])->save();
        $this->travelTo($newEnd->subMinutes(2));

        (new CloseAuctionJob($auction->id))->handle($this->closer);

        Queue::assertPushed(CloseAuctionJob::class, fn (CloseAuctionJob $job) => $job->auctionId === $auction->id
            && $job->delay->equalTo($newEnd));
        $this->assertSame(AuctionStatus::Live, $auction->fresh()->status);
    }

    public function test_job_closes_a_due_auction(): void
    {
        $auction = $this->liveAuction();
        $this->travelTo($auction->end_at);

        (new CloseAuctionJob($auction->id))->handle($this->closer);

        $this->assertSame(AuctionStatus::Completed, $auction->fresh()->status);
    }

    public function test_only_one_pending_close_job_exists_per_auction(): void
    {
        Queue::fake();
        $auction = $this->liveAuction();

        CloseAuctionJob::scheduleFor($auction);
        CloseAuctionJob::scheduleFor($auction);

        Queue::assertPushed(CloseAuctionJob::class, 1);
    }

    public function test_sweep_closes_only_live_auctions_past_their_end(): void
    {
        $due = $this->liveAuction();
        $notDue = $this->liveAuction(['end_at' => now()->addHours(5), 'original_end_at' => now()->addHours(5)]);
        $cancelled = Auction::factory()->cancelled()->create();
        $this->travelTo($due->end_at->addSecond());

        $this->artisan('auctions:close-due')->expectsOutput('Closed 1 auction(s).')->assertSuccessful();

        $this->assertSame(AuctionStatus::Completed, $due->fresh()->status);
        $this->assertSame(AuctionStatus::Live, $notDue->fresh()->status);
        $this->assertSame(AuctionStatus::Cancelled, $cancelled->fresh()->status);
    }
}
