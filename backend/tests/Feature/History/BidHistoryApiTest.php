<?php

namespace Tests\Feature\History;

use App\Models\Auction;
use App\Models\User;
use App\Services\Bidding\BidService;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BidHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Auction $auction;

    private User $a;

    private User $b;

    private User $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auction = Auction::factory()->live()->create([
            'starting_price' => 10_000,
            'reserve_price' => null,
            'min_increment' => 500,
        ]);
        [$this->a, $this->b, $this->c] = User::factory()->count(3)->create()->all();

        // A sets a private 20,000 maximum; B bids manually and is answered automatically.
        $bids = app(BidService::class);
        $bids->configureAutoBid($this->auction, $this->a, 20_000); // A AUTO 10,000
        $this->travel(1)->seconds();
        $bids->placeBid($this->auction, $this->b, 12_000);           // B MANUAL 12,000, A AUTO 12,500
        $this->travel(1)->seconds();
        $bids->placeBid($this->auction, $this->c, 25_000);           // C MANUAL 25,000
    }

    private function history(?User $viewer = null, string $query = ''): TestResponse
    {
        if ($viewer) {
            $this->actingAs($viewer);
        }

        return $this->getJson("/api/auctions/{$this->auction->id}/bids{$query}")->assertOk();
    }

    public function test_history_is_newest_first_with_pseudonyms_and_types(): void
    {
        $rows = $this->history()->json('data');

        $this->assertSame(
            [[25_000, 'MANUAL', 'Bidder 3'], [12_500, 'AUTO', 'Bidder 1'], [12_000, 'MANUAL', 'Bidder 2'], [10_000, 'AUTO', 'Bidder 1']],
            array_map(fn ($row) => [$row['amount'], $row['type'], $row['bidder']], $rows),
        );
    }

    public function test_viewer_sees_you_for_their_own_bids(): void
    {
        $rows = $this->history($this->a)->json('data');

        $this->assertSame(['Bidder 3', 'You', 'Bidder 2', 'You'], array_column($rows, 'bidder'));
        $this->assertSame([false, true, false, true], array_column($rows, 'is_you'));
    }

    public function test_guests_can_watch_the_history(): void
    {
        $this->history()
            ->assertJsonCount(4, 'data')
            ->assertJsonStructure(['data' => [['id', 'amount', 'type', 'bidder', 'is_you', 'created_at']], 'meta' => ['server_time']]);
    }

    public function test_history_never_exposes_identities_or_maximums(): void
    {
        $response = $this->history($this->b);

        $keys = [];
        $body = $response->json();
        array_walk_recursive($body, function ($value, $key) use (&$keys) {
            $keys[] = $key;
        });
        foreach (['user_id', 'email', 'name', 'max_amount'] as $private) {
            $this->assertNotContains($private, $keys);
        }

        $content = $response->getContent();
        $this->assertStringNotContainsString($this->a->email, $content);
        $this->assertStringNotContainsString('20000', $content, "A's private maximum leaked.");
    }

    public function test_history_pages_stay_stable_while_new_bids_arrive(): void
    {
        $firstPage = $this->history(query: '?per_page=2');
        $this->assertSame([25_000, 12_500], array_column($firstPage->json('data'), 'amount'));

        // A new bid lands at the top between page requests.
        app(BidService::class)->placeBid($this->auction, $this->b, 30_000);

        $cursor = $firstPage->json('meta.next_cursor');
        $secondPage = $this->history(query: "?per_page=2&cursor={$cursor}");
        $this->assertSame([12_000, 10_000], array_column($secondPage->json('data'), 'amount'));
    }

    public function test_auction_shows_the_leader_and_winner_by_pseudonym(): void
    {
        $this->getJson("/api/auctions/{$this->auction->id}")->assertJsonPath('data.leader', 'Bidder 3');
        $this->actingAs($this->c)->getJson("/api/auctions/{$this->auction->id}")->assertJsonPath('data.leader', 'You');

        $this->travelTo($this->auction->end_at);
        app(AuctionCloser::class)->close($this->auction->id);

        $this->actingAs($this->b)->getJson("/api/auctions/{$this->auction->id}")
            ->assertJsonPath('data.winner', 'Bidder 3')
            ->assertJsonPath('data.is_winner', false);
        $this->actingAs($this->c)->getJson("/api/auctions/{$this->auction->id}")
            ->assertJsonPath('data.winner', 'You')
            ->assertJsonPath('data.is_winner', true);
    }

    public function test_draft_history_is_not_found(): void
    {
        $draft = Auction::factory()->create();

        $this->getJson("/api/auctions/{$draft->id}/bids")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
    }

    public function test_bids_cannot_be_changed_or_deleted_through_the_api(): void
    {
        $bidId = $this->auction->bids()->value('id');

        $this->actingAs($this->a)->putJson("/api/auctions/{$this->auction->id}/bids/{$bidId}", ['amount' => 1])->assertStatus(404);
        $this->actingAs($this->a)->deleteJson("/api/auctions/{$this->auction->id}/bids/{$bidId}")->assertStatus(404);
        $this->actingAs($this->a)->deleteJson("/api/auctions/{$this->auction->id}/bids")->assertStatus(405);

        $this->assertSame(4, $this->auction->bids()->count());
    }
}
