<?php

namespace Tests\Feature\Services;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\User;
use App\Services\AuctionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuctionLifecycleServiceTest extends TestCase
{
    use RefreshDatabase;

    private AuctionLifecycleService $service;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AuctionLifecycleService::class);
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Vintage Ceylon Tea Chest',
            'description' => 'Teak tea chest, circa 1920.',
            'starting_price' => 20_000,
            'reserve_price' => 50_000,
            'min_increment' => 500,
            'start_at' => Carbon::parse('+1 day')->startOfMinute(),
            'end_at' => Carbon::parse('+1 day 2 hours')->startOfMinute(),
            'anti_snipe_seconds' => 120,
            'extension_seconds' => 120,
            'max_extensions' => 3,
        ], $overrides);
    }

    private function assertApiError(ErrorCode $code, callable $action): void
    {
        try {
            $action();
            $this->fail("Expected {$code->value}.");
        } catch (ApiException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public function test_create_stores_a_draft_with_its_original_end_and_records_the_event(): void
    {
        $auction = $this->service->create($this->data(), $this->admin)->fresh();

        $this->assertSame(AuctionStatus::Draft, $auction->status);
        $this->assertSame($this->admin->id, $auction->created_by);
        $this->assertTrue($auction->original_end_at->eq($auction->end_at));

        $event = AuctionEvent::sole();
        $this->assertSame(AuctionEventType::AuctionCreated, $event->type);
        $this->assertSame($this->admin->id, $event->actor_id);
        $this->assertSame(50_000, $event->payload['reserve_price']);
    }

    public function test_create_can_schedule_immediately(): void
    {
        $auction = $this->service->create($this->data(), $this->admin, schedule: true);

        $this->assertSame(AuctionStatus::Scheduled, $auction->fresh()->status);
        $this->assertSame(
            [AuctionEventType::AuctionCreated, AuctionEventType::AuctionScheduled],
            AuctionEvent::orderBy('id')->pluck('type')->all(),
        );
    }

    public function test_create_and_schedule_with_a_past_start_is_rolled_back_entirely(): void
    {
        try {
            $this->service->create($this->data(['start_at' => Carbon::parse('-1 minute')]), $this->admin, schedule: true);
            $this->fail('Scheduling with a past start must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_at', $e->errors());
        }

        $this->assertSame(0, Auction::count());
        $this->assertSame(0, AuctionEvent::count());
    }

    public function test_update_changes_a_draft_and_records_only_what_changed(): void
    {
        $auction = $this->service->create($this->data(), $this->admin);
        $newEnd = Carbon::parse('+1 day 3 hours')->startOfMinute();

        $this->service->update($auction, $this->data(['title' => 'Renamed', 'end_at' => $newEnd]), $this->admin);

        $auction->refresh();
        $this->assertSame('Renamed', $auction->title);
        $this->assertTrue($auction->original_end_at->eq($newEnd), 'original_end_at follows end_at while drafting');

        $event = AuctionEvent::where('type', AuctionEventType::AuctionUpdated)->sole();
        $this->assertSame(['title', 'end_at'], array_keys($event->payload['changes']));
        $this->assertSame('Renamed', $event->payload['changes']['title']['to']);
    }

    public function test_update_without_changes_records_nothing(): void
    {
        $auction = $this->service->create($this->data(), $this->admin);

        $this->service->update($auction, $this->data(), $this->admin);

        $this->assertSame(0, AuctionEvent::where('type', AuctionEventType::AuctionUpdated)->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonDraftStates(): array
    {
        return ['scheduled' => ['scheduled'], 'live' => ['live'], 'completed' => ['completed'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('nonDraftStates')]
    public function test_only_drafts_can_be_edited(string $state): void
    {
        $auction = Auction::factory()->{$state}()->create(['title' => 'Original']);

        $this->assertApiError(
            ErrorCode::AuctionNotEditable,
            fn () => $this->service->update($auction, $this->data(['title' => 'Changed']), $this->admin),
        );
        $this->assertSame('Original', $auction->fresh()->title);
    }

    public function test_schedule_moves_a_draft_to_scheduled(): void
    {
        $auction = Auction::factory()->create();

        $this->service->schedule($auction, $this->admin);

        $this->assertSame(AuctionStatus::Scheduled, $auction->fresh()->status);
        $event = AuctionEvent::where('type', AuctionEventType::AuctionScheduled)->sole();
        $this->assertSame($auction->start_at->toIso8601ZuluString(), $event->payload['start_at']);
    }

    public function test_schedule_rejects_a_start_time_in_the_past(): void
    {
        $auction = Auction::factory()->create([
            'start_at' => now()->subMinute(),
            'end_at' => now()->addHour(),
            'original_end_at' => now()->addHour(),
        ]);

        $this->expectException(ValidationException::class);

        try {
            $this->service->schedule($auction, $this->admin);
        } finally {
            $this->assertSame(AuctionStatus::Draft, $auction->fresh()->status);
        }
    }

    public function test_schedule_rejects_an_auction_that_is_not_a_draft(): void
    {
        $auction = Auction::factory()->scheduled()->create();

        $this->assertApiError(ErrorCode::InvalidTransition, fn () => $this->service->schedule($auction, $this->admin));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function cancellableStates(): array
    {
        return [
            'draft' => ['create', 'DRAFT'],
            'scheduled' => ['scheduled', 'SCHEDULED'],
            'live' => ['live', 'LIVE'],
        ];
    }

    #[DataProvider('cancellableStates')]
    public function test_cancel_is_allowed_before_completion(string $factoryState, string $fromStatus): void
    {
        $auction = $factoryState === 'create'
            ? Auction::factory()->create()
            : Auction::factory()->{$factoryState}()->create();

        $this->service->cancel($auction, $this->admin, 'Item withdrawn by seller');

        $auction->refresh();
        $this->assertSame(AuctionStatus::Cancelled, $auction->status);
        $this->assertNotNull($auction->cancelled_at);

        $event = AuctionEvent::where('type', AuctionEventType::AuctionCancelled)->sole();
        $this->assertSame($fromStatus, $event->payload['from_status']);
        $this->assertSame('Item withdrawn by seller', $event->payload['reason']);
        $this->assertSame($this->admin->id, $event->actor_id);
    }

    public function test_completed_and_cancelled_auctions_cannot_be_cancelled(): void
    {
        foreach ([Auction::factory()->completed()->create(), Auction::factory()->cancelled()->create()] as $auction) {
            $before = $auction->status;

            $this->assertApiError(ErrorCode::InvalidTransition, fn () => $this->service->cancel($auction, $this->admin));
            $this->assertSame($before, $auction->fresh()->status);
        }

        $this->assertSame(0, AuctionEvent::count());
    }
}
