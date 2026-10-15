<?php

namespace Tests\Feature\Lifecycle;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Jobs\CloseAuctionJob;
use App\Jobs\StartAuctionJob;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\User;
use App\Services\AuctionLifecycleService;
use App\Services\Bidding\BidService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AuctionStartingTest extends TestCase
{
    use RefreshDatabase;

    private AuctionLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->lifecycle = app(AuctionLifecycleService::class);
    }

    public function test_scheduling_queues_a_start_job_for_the_start_time(): void
    {
        $auction = Auction::factory()->create();

        $this->lifecycle->schedule($auction, User::factory()->admin()->create());

        Queue::assertPushed(StartAuctionJob::class, fn (StartAuctionJob $job) => $job->auctionId === $auction->id
            && $job->delay->equalTo($auction->start_at));
    }

    public function test_due_scheduled_auction_starts_and_queues_its_close(): void
    {
        $auction = Auction::factory()->scheduled()->create();
        $this->travelTo($auction->start_at);

        $this->assertTrue($this->lifecycle->start($auction->id));

        $fresh = $auction->fresh();
        $this->assertSame(AuctionStatus::Live, $fresh->status);
        $this->assertNotNull($fresh->started_at);

        $event = AuctionEvent::where('type', AuctionEventType::AuctionStarted)->sole();
        $this->assertNull($event->actor_id, 'Starting is a system action.');

        Queue::assertPushed(CloseAuctionJob::class, fn (CloseAuctionJob $job) => $job->auctionId === $auction->id
            && $job->delay->equalTo($auction->end_at));
    }

    public function test_started_auction_accepts_bids(): void
    {
        $auction = Auction::factory()->scheduled()->create(['reserve_price' => null]);
        $this->travelTo($auction->start_at->addSecond());
        $this->lifecycle->start($auction->id);

        $result = app(BidService::class)->placeBid($auction, User::factory()->create(), $auction->starting_price);

        $this->assertSame($auction->starting_price, $result->auction->current_price);
    }

    public function test_auction_is_not_started_before_its_start_time(): void
    {
        $auction = Auction::factory()->scheduled()->create();
        $this->travelTo($auction->start_at->subSecond());

        $this->assertFalse($this->lifecycle->start($auction->id));
        $this->assertSame(AuctionStatus::Scheduled, $auction->fresh()->status);
    }

    public function test_starting_twice_is_harmless(): void
    {
        $auction = Auction::factory()->scheduled()->create();
        $this->travelTo($auction->start_at);

        $this->assertTrue($this->lifecycle->start($auction->id));
        $this->assertFalse($this->lifecycle->start($auction->id));

        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::AuctionStarted)->count());
        Queue::assertPushed(CloseAuctionJob::class, 1);
    }

    public function test_drafts_and_cancelled_auctions_are_never_started(): void
    {
        $draft = Auction::factory()->create();
        $cancelled = Auction::factory()->cancelled()->create();
        $this->travelTo($draft->start_at->addHour());

        $this->assertFalse($this->lifecycle->start($draft->id));
        $this->assertFalse($this->lifecycle->start($cancelled->id));
        $this->assertSame(AuctionStatus::Draft, $draft->fresh()->status);
        $this->assertSame(AuctionStatus::Cancelled, $cancelled->fresh()->status);
    }

    public function test_start_job_opens_the_auction(): void
    {
        $auction = Auction::factory()->scheduled()->create();
        $this->travelTo($auction->start_at);

        (new StartAuctionJob($auction->id))->handle($this->lifecycle);

        $this->assertSame(AuctionStatus::Live, $auction->fresh()->status);
    }

    public function test_start_job_picked_up_early_tries_again_at_the_start_time(): void
    {
        $auction = Auction::factory()->scheduled()->create();
        $this->travelTo($auction->start_at->subMinutes(5));

        (new StartAuctionJob($auction->id))->handle($this->lifecycle);

        $this->assertSame(AuctionStatus::Scheduled, $auction->fresh()->status);
        Queue::assertPushed(StartAuctionJob::class, fn (StartAuctionJob $job) => $job->delay->equalTo($auction->start_at));
    }

    public function test_sweep_starts_only_due_scheduled_auctions(): void
    {
        $due = Auction::factory()->scheduled()->create();
        $later = Auction::factory()->scheduled()->create([
            'start_at' => $due->start_at->addDay(),
            'end_at' => $due->start_at->addDays(2),
            'original_end_at' => $due->start_at->addDays(2),
        ]);
        $draft = Auction::factory()->create();
        $this->travelTo($due->start_at->addSecond());

        $this->artisan('auctions:start-due')->expectsOutput('Started 1 auction(s).')->assertSuccessful();

        $this->assertSame(AuctionStatus::Live, $due->fresh()->status);
        $this->assertSame(AuctionStatus::Scheduled, $later->fresh()->status);
        $this->assertSame(AuctionStatus::Draft, $draft->fresh()->status);
    }
}
