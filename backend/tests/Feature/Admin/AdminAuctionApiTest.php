<?php

namespace Tests\Feature\Admin;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuctionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Signed Cricket Bat',
            'description' => 'Signed by the 1996 World Cup squad.',
            'starting_price' => 50_000,
            'reserve_price' => 120_000,
            'min_increment' => 1_000,
            'start_at' => now()->addDay()->startOfMinute()->toIso8601ZuluString(),
            'end_at' => now()->addDay()->addHours(2)->startOfMinute()->toIso8601ZuluString(),
            'anti_snipe_seconds' => 120,
            'extension_seconds' => 120,
            'max_extensions' => 3,
        ], $overrides);
    }

    // --- Create -------------------------------------------------------------

    public function test_admin_creates_a_draft_auction(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/auctions', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.reserve_price', 120_000)
            ->assertJsonPath('data.starting_price', 50_000)
            ->assertJsonPath('data.created_by', $this->admin->id)
            ->assertJsonPath('data.bids_count', 0)
            ->assertJsonStructure(['meta' => ['server_time']]);

        $this->assertSame(AuctionEventType::AuctionCreated, AuctionEvent::sole()->type);
    }

    public function test_admin_can_create_and_schedule_in_one_request(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/auctions', $this->payload(['status' => 'SCHEDULED']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'SCHEDULED');
    }

    public function test_times_with_an_offset_are_stored_in_utc(): void
    {
        $this->actingAs($this->admin)->postJson('/api/admin/auctions', $this->payload([
            'start_at' => '2030-06-01T15:30:00+05:30',
            'end_at' => '2030-06-01T17:30:00+05:30',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.start_at', '2030-06-01T10:00:00Z')
            ->assertJsonPath('data.end_at', '2030-06-01T12:00:00Z');

        $this->assertSame('2030-06-01 10:00:00', Auction::sole()->getRawOriginal('start_at'));
    }

    public function test_reserve_price_is_optional(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/auctions', $this->payload(['reserve_price' => null]))
            ->assertCreated()
            ->assertJsonPath('data.reserve_price', null);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'missing title' => [['title' => ''], 'title'],
            'end before start' => [['end_at' => '2020-01-01T00:00:00Z'], 'end_at'],
            'end equal to start' => [['start_at' => '2030-01-01T10:00:00Z', 'end_at' => '2030-01-01T10:00:00Z'], 'end_at'],
            'shorter than a minute' => [['start_at' => '2030-01-01T10:00:00Z', 'end_at' => '2030-01-01T10:00:30Z'], 'end_at'],
            'reserve below starting price' => [['reserve_price' => 49_999], 'reserve_price'],
            'zero starting price' => [['starting_price' => 0], 'starting_price'],
            'negative starting price' => [['starting_price' => -100], 'starting_price'],
            'fractional money' => [['starting_price' => 50_000.5], 'starting_price'],
            'money as a string' => [['starting_price' => '50000'], 'starting_price'],
            'zero increment' => [['min_increment' => 0], 'min_increment'],
            'negative anti-snipe window' => [['anti_snipe_seconds' => -1], 'anti_snipe_seconds'],
            'anti-snipe without extension' => [['extension_seconds' => 0], 'extension_seconds'],
            'anti-snipe without allowed extensions' => [['max_extensions' => 0], 'max_extensions'],
            'anti-snipe window as long as the auction' => [[
                'start_at' => '2030-01-01T10:00:00Z', 'end_at' => '2030-01-01T10:02:00Z', 'anti_snipe_seconds' => 120,
            ], 'anti_snipe_seconds'],
            'too many extensions' => [['max_extensions' => 51], 'max_extensions'],
            'scheduling with a past start' => [['status' => 'SCHEDULED', 'start_at' => '2020-01-01T00:00:00Z'], 'start_at'],
            'created as live' => [['status' => 'LIVE'], 'status'],
            'not a date' => [['start_at' => 'tomorrow-ish'], 'start_at'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_create_validates_input(array $overrides, string $field): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/auctions', $this->payload($overrides))
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['errors' => [$field]]]]);

        $this->assertSame(0, Auction::count());
    }

    public function test_anti_sniping_can_be_disabled(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/admin/auctions', $this->payload([
                'anti_snipe_seconds' => 0, 'extension_seconds' => 0, 'max_extensions' => 0,
            ]))
            ->assertCreated();
    }

    public function test_a_draft_may_be_saved_with_a_past_start_but_not_scheduled(): void
    {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/admin/auctions', $this->payload([
                'start_at' => now()->subHour()->toIso8601ZuluString(),
                'end_at' => now()->addHour()->toIso8601ZuluString(),
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin)
            ->postJson("/api/admin/auctions/{$id}/schedule")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['start_at']]]]);
    }

    // --- Read ---------------------------------------------------------------

    public function test_admin_lists_auctions_of_every_status_and_can_filter(): void
    {
        Auction::factory()->create();
        Auction::factory()->live()->create();
        Auction::factory()->cancelled()->create();

        $this->actingAs($this->admin)->getJson('/api/admin/auctions')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data', 'links', 'meta' => ['current_page', 'total', 'server_time']]);

        $this->actingAs($this->admin)->getJson('/api/admin/auctions?status=LIVE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'LIVE');
    }

    public function test_list_rejects_an_unknown_status_filter(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/auctions?status=PAUSED')
            ->assertUnprocessable();
    }

    public function test_admin_sees_a_draft_with_its_reserve(): void
    {
        $auction = Auction::factory()->create(['reserve_price' => 77_000]);

        $this->actingAs($this->admin)->getJson("/api/admin/auctions/{$auction->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $auction->id)
            ->assertJsonPath('data.reserve_price', 77_000);
    }

    public function test_unknown_auction_returns_auction_not_found(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/auctions/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
    }

    // --- Update -------------------------------------------------------------

    public function test_admin_updates_a_draft(): void
    {
        $auction = Auction::factory()->create();

        $this->actingAs($this->admin)
            ->putJson("/api/admin/auctions/{$auction->id}", $this->payload(['title' => 'Updated title']))
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated title');

        $this->assertSame(1, AuctionEvent::where('type', AuctionEventType::AuctionUpdated)->count());
    }

    public function test_update_cannot_change_the_status(): void
    {
        $auction = Auction::factory()->create();

        $this->actingAs($this->admin)
            ->putJson("/api/admin/auctions/{$auction->id}", $this->payload(['status' => 'SCHEDULED']))
            ->assertUnprocessable();

        $this->assertSame(AuctionStatus::Draft, $auction->fresh()->status);
    }

    public function test_scheduled_auction_cannot_be_edited(): void
    {
        $auction = Auction::factory()->scheduled()->create();

        $this->actingAs($this->admin)
            ->putJson("/api/admin/auctions/{$auction->id}", $this->payload())
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'AUCTION_NOT_EDITABLE')
            ->assertJsonPath('error.details.status', 'SCHEDULED');
    }

    // --- Schedule and cancel ------------------------------------------------

    public function test_admin_schedules_a_draft(): void
    {
        $auction = Auction::factory()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/auctions/{$auction->id}/schedule")
            ->assertOk()
            ->assertJsonPath('data.status', 'SCHEDULED');
    }

    public function test_scheduling_twice_is_an_invalid_transition(): void
    {
        $auction = Auction::factory()->scheduled()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/auctions/{$auction->id}/schedule")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_TRANSITION')
            ->assertJsonPath('error.details.from', 'SCHEDULED')
            ->assertJsonPath('error.details.to', 'SCHEDULED');
    }

    public function test_admin_cancels_a_live_auction_with_a_reason(): void
    {
        $auction = Auction::factory()->live()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/auctions/{$auction->id}/cancel", ['reason' => 'Item damaged in storage'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $event = AuctionEvent::where('type', AuctionEventType::AuctionCancelled)->sole();
        $this->assertSame('LIVE', $event->payload['from_status']);
        $this->assertSame('Item damaged in storage', $event->payload['reason']);
    }

    public function test_completed_auction_cannot_be_cancelled(): void
    {
        $auction = Auction::factory()->completed()->create();

        $this->actingAs($this->admin)
            ->postJson("/api/admin/auctions/{$auction->id}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_TRANSITION');
    }

    // --- Authorization ------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function adminEndpoints(): array
    {
        return [
            'list' => ['GET', '/api/admin/auctions'],
            'create' => ['POST', '/api/admin/auctions'],
            'show' => ['GET', '/api/admin/auctions/{id}'],
            'update' => ['PUT', '/api/admin/auctions/{id}'],
            'schedule' => ['POST', '/api/admin/auctions/{id}/schedule'],
            'cancel' => ['POST', '/api/admin/auctions/{id}/cancel'],
        ];
    }

    #[DataProvider('adminEndpoints')]
    public function test_bidders_are_forbidden_from_admin_endpoints(string $method, string $uri): void
    {
        $auction = Auction::factory()->create();

        $this->actingAs(User::factory()->create())
            ->json($method, str_replace('{id}', (string) $auction->id, $uri), $this->payload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertSame(AuctionStatus::Draft, $auction->fresh()->status);
    }

    #[DataProvider('adminEndpoints')]
    public function test_guests_are_rejected_from_admin_endpoints(string $method, string $uri): void
    {
        $auction = Auction::factory()->create();

        $this->json($method, str_replace('{id}', (string) $auction->id, $uri))
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_bidder_probing_a_missing_admin_auction_gets_403_not_404(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/api/admin/auctions/999999')
            ->assertForbidden();
    }
}
