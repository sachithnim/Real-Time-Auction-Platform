<?php

namespace Tests\Feature\Services;

use App\Enums\AuctionEventType;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\User;
use App\Services\AuctionEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AuctionEventRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_type_actor_and_payload(): void
    {
        $auction = Auction::factory()->create();
        $admin = User::factory()->admin()->create();

        $event = app(AuctionEventRecorder::class)->record(
            $auction,
            AuctionEventType::AuctionCancelled,
            $admin,
            ['from_status' => 'DRAFT', 'reason' => 'Duplicate listing'],
        );

        $stored = $event->fresh();
        $this->assertSame(AuctionEventType::AuctionCancelled, $stored->type);
        $this->assertSame($admin->id, $stored->actor_id);
        // MySQL's JSON type does not preserve key order, so compare as a map.
        $this->assertEquals(['from_status' => 'DRAFT', 'reason' => 'Duplicate listing'], $stored->payload);
    }

    public function test_system_events_have_no_actor(): void
    {
        $event = app(AuctionEventRecorder::class)->record(
            Auction::factory()->create(),
            AuctionEventType::AuctionStarted,
            null,
        );

        $this->assertNull($event->fresh()->actor_id);
    }

    public function test_event_is_rolled_back_with_the_failed_change(): void
    {
        $auction = Auction::factory()->create();

        try {
            DB::transaction(function () use ($auction) {
                app(AuctionEventRecorder::class)->record($auction, AuctionEventType::AuctionScheduled, null);
                throw new RuntimeException('change failed after the event was written');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, AuctionEvent::count());
    }
}
