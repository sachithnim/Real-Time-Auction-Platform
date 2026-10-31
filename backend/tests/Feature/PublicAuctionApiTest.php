<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PublicAuctionApiTest extends TestCase
{
    use RefreshDatabase;

    /** Fields that must never reach a bidder. */
    private const PRIVATE_FIELDS = ['reserve_price', 'created_by', 'leader_id', 'winner_id', 'email', 'max_amount'];

    private function assertNoPrivateFields(TestResponse $response): void
    {
        $keys = [];
        $body = $response->json();
        array_walk_recursive($body, function ($value, $key) use (&$keys) {
            $keys[] = $key;
        });

        foreach (self::PRIVATE_FIELDS as $field) {
            $this->assertNotContains($field, $keys, "Private field '{$field}' leaked to a bidder.");
        }
    }

    public function test_guest_can_list_auctions_without_drafts(): void
    {
        Auction::factory()->create(['title' => 'Secret draft']);
        Auction::factory()->scheduled()->create();
        Auction::factory()->live()->create();
        Auction::factory()->completed()->create();
        Auction::factory()->cancelled()->create();

        $response = $this->getJson('/api/auctions')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonMissing(['title' => 'Secret draft'])
            ->assertJsonStructure(['meta' => ['server_time', 'total']]);

        $this->assertNotContains('DRAFT', array_column($response->json('data'), 'status'));
    }

    public function test_listing_never_exposes_private_fields(): void
    {
        Auction::factory()->live()->create(['reserve_price' => 90_000]);

        $response = $this->actingAs(User::factory()->create())->getJson('/api/auctions')->assertOk();

        $this->assertNoPrivateFields($response);
        $response->assertJsonMissing(['reserve_price' => 90_000]);
    }

    public function test_listing_shows_live_first_then_upcoming_then_finished(): void
    {
        $finished = Auction::factory()->completed()->create();
        $upcomingLater = Auction::factory()->scheduled()->create([
            'start_at' => now()->addDays(3), 'end_at' => now()->addDays(4), 'original_end_at' => now()->addDays(4),
        ]);
        $liveEndingLater = Auction::factory()->live()->create(['end_at' => now()->addHours(5), 'original_end_at' => now()->addHours(5)]);
        $upcomingSoon = Auction::factory()->scheduled()->create([
            'start_at' => now()->addHour(), 'end_at' => now()->addHours(2), 'original_end_at' => now()->addHours(2),
        ]);
        $liveEndingSoon = Auction::factory()->live()->create(['end_at' => now()->addMinutes(5), 'original_end_at' => now()->addMinutes(5)]);

        $ids = array_column($this->getJson('/api/auctions')->json('data'), 'id');

        $this->assertSame(
            [$liveEndingSoon->id, $liveEndingLater->id, $upcomingSoon->id, $upcomingLater->id, $finished->id],
            $ids,
        );
    }

    public function test_listing_can_search_titles_by_every_word(): void
    {
        $telescope = Auction::factory()->live()->create(['title' => 'Antique Brass Telescope']);
        Auction::factory()->live()->create(['title' => 'Brass Door Knocker']);
        Auction::factory()->live()->create(['title' => 'Signed Cricket Bat']);

        $this->getJson('/api/auctions?search=brass')->assertOk()->assertJsonCount(2, 'data');

        // Every word must match, in any order, ignoring case.
        $this->getJson('/api/auctions?search=telescope%20BRASS')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $telescope->id);
    }

    public function test_search_treats_like_wildcards_as_plain_text(): void
    {
        Auction::factory()->live()->create(['title' => '100% Silk Saree']);
        Auction::factory()->live()->create(['title' => '1000 Year Old Coin']);

        $this->getJson('/api/auctions?search='.urlencode('100%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '100% Silk Saree');

        $this->getJson('/api/auctions?search=_')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_search_never_finds_drafts(): void
    {
        Auction::factory()->create(['title' => 'Hidden Brass Draft']);

        $this->getJson('/api/auctions?search=brass')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_listing_can_sort_by_price(): void
    {
        $cheap = Auction::factory()->live()->create(['starting_price' => 1_000, 'current_price' => null]);
        $mid = Auction::factory()->live()->create(['starting_price' => 500, 'current_price' => 20_000]);
        $dear = Auction::factory()->scheduled()->create(['starting_price' => 50_000, 'current_price' => null]);

        $ids = fn (string $sort) => array_column($this->getJson("/api/auctions?sort={$sort}")->json('data'), 'id');

        // Ordered by what a bidder sees: the current price, or the starting price before any bid.
        $this->assertSame([$dear->id, $mid->id, $cheap->id], $ids('price_high'));
        $this->assertSame([$cheap->id, $mid->id, $dear->id], $ids('price_low'));
    }

    public function test_listing_can_sort_by_ending_soonest_and_newest(): void
    {
        $finished = Auction::factory()->completed()->create(['created_at' => now()->subDays(3)]);
        $endsLater = Auction::factory()->live()->create([
            'end_at' => now()->addHours(5), 'original_end_at' => now()->addHours(5), 'created_at' => now()->subDays(2),
        ]);
        $endsSoon = Auction::factory()->live()->create([
            'end_at' => now()->addMinutes(5), 'original_end_at' => now()->addMinutes(5), 'created_at' => now()->subDay(),
        ]);

        $ids = fn (string $sort) => array_column($this->getJson("/api/auctions?sort={$sort}")->json('data'), 'id');

        // Open auctions first by the soonest end; finished ones (already ended) after.
        $this->assertSame([$endsSoon->id, $endsLater->id, $finished->id], $ids('ending_soon'));
        $this->assertSame([$endsSoon->id, $endsLater->id, $finished->id], $ids('newest'));
    }

    public function test_listing_rejects_an_unknown_sort_or_a_huge_search(): void
    {
        $this->getJson('/api/auctions?sort=random')
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['sort']]]]);

        $this->getJson('/api/auctions?search='.str_repeat('a', 101))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['search']]]]);
    }

    public function test_listing_can_filter_by_status_but_not_by_draft(): void
    {
        Auction::factory()->live()->create();
        Auction::factory()->scheduled()->create();

        $this->getJson('/api/auctions?status=LIVE')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'LIVE');

        $this->getJson('/api/auctions?status=DRAFT')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_details_show_reserve_met_but_never_the_reserve(): void
    {
        $notMet = Auction::factory()->live()->create(['starting_price' => 20_000, 'reserve_price' => 50_000]);
        $met = Auction::factory()->live()->create(['starting_price' => 20_000, 'reserve_price' => 50_000]);
        $met->forceFill(['current_price' => 50_000])->save();
        $noReserve = Auction::factory()->live()->withoutReserve()->create();

        $response = $this->getJson("/api/auctions/{$notMet->id}")
            ->assertOk()
            ->assertJsonPath('data.reserve_met', false)
            ->assertJsonStructure(['data', 'meta' => ['server_time']]);
        $this->assertNoPrivateFields($response);

        $this->getJson("/api/auctions/{$met->id}")->assertJsonPath('data.reserve_met', true);
        $this->getJson("/api/auctions/{$noReserve->id}")->assertJsonPath('data.reserve_met', true);
    }

    public function test_details_include_timing_fields_in_utc(): void
    {
        $auction = Auction::factory()->live()->create();

        $this->getJson("/api/auctions/{$auction->id}")
            ->assertOk()
            ->assertJsonPath('data.end_at', $auction->end_at->utc()->format('Y-m-d\TH:i:s\Z'))
            ->assertJsonPath('data.extensions_count', 0)
            ->assertJsonPath('data.max_extensions', 3);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $this->getJson("/api/auctions/{$auction->id}")->json('meta.server_time'),
        );
    }

    public function test_details_show_the_minimum_next_bid(): void
    {
        $noBids = Auction::factory()->live()->create(['starting_price' => 20_000, 'min_increment' => 500]);
        $withBids = Auction::factory()->live()->create(['starting_price' => 10_000, 'min_increment' => 500]);
        $withBids->forceFill(['current_price' => 25_000, 'leader_id' => User::factory()->create()->id])->save();
        $finished = Auction::factory()->completed()->create();

        $this->getJson("/api/auctions/{$noBids->id}")->assertJsonPath('data.min_next_bid', 20_000);
        $this->getJson("/api/auctions/{$withBids->id}")->assertJsonPath('data.min_next_bid', 25_500);
        $this->getJson("/api/auctions/{$finished->id}")->assertJsonPath('data.min_next_bid', null);
    }

    public function test_viewer_learns_only_whether_they_are_leading(): void
    {
        $leader = User::factory()->create();
        $other = User::factory()->create();
        $auction = Auction::factory()->live()->create();
        $auction->forceFill(['current_price' => $auction->starting_price, 'leader_id' => $leader->id])->save();

        $this->actingAs($leader)->getJson("/api/auctions/{$auction->id}")->assertJsonPath('data.is_leading', true);
        $this->actingAs($other)->getJson("/api/auctions/{$auction->id}")->assertJsonPath('data.is_leading', false);
    }

    public function test_draft_details_look_like_a_missing_auction_to_bidders(): void
    {
        $draft = Auction::factory()->create();

        $this->actingAs(User::factory()->create())
            ->getJson("/api/auctions/{$draft->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');

        $this->getJson("/api/auctions/{$draft->id}")->assertNotFound();
    }

    public function test_admin_can_open_a_draft_through_the_public_endpoint(): void
    {
        $draft = Auction::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->getJson("/api/auctions/{$draft->id}")
            ->assertOk();
    }

    public function test_unknown_auction_returns_auction_not_found(): void
    {
        $this->getJson('/api/auctions/999999')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
    }
}
