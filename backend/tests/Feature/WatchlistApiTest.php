<?php

namespace Tests\Feature;

use App\Models\Auction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WatchlistApiTest extends TestCase
{
    use RefreshDatabase;

    private User $bidder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bidder = User::factory()->create();
    }

    private function watch(Auction|int $auction, ?User $user = null)
    {
        $id = $auction instanceof Auction ? $auction->id : $auction;

        return $this->actingAs($user ?? $this->bidder)->putJson("/api/auctions/{$id}/watch");
    }

    private function unwatch(Auction $auction, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->bidder)->deleteJson("/api/auctions/{$auction->id}/watch");
    }

    private function rows(): int
    {
        return DB::table('watchlists')->count();
    }

    public function test_a_bidder_watches_an_auction(): void
    {
        $auction = Auction::factory()->live()->create();

        $this->watch($auction)
            ->assertOk()
            ->assertJsonPath('data', ['auction_id' => $auction->id, 'is_watching' => true])
            ->assertJsonStructure(['meta' => ['server_time']]);

        $this->assertTrue($this->bidder->isWatching($auction));
    }

    public function test_watching_twice_keeps_a_single_entry(): void
    {
        $auction = Auction::factory()->live()->create();

        $this->watch($auction)->assertOk();
        $this->watch($auction)->assertOk()->assertJsonPath('data.is_watching', true);

        $this->assertSame(1, $this->rows());
    }

    public function test_a_bidder_unwatches_and_unwatching_again_is_harmless(): void
    {
        $auction = Auction::factory()->live()->create();
        $this->watch($auction);

        $this->unwatch($auction)->assertOk()->assertJsonPath('data.is_watching', false);
        $this->unwatch($auction)->assertOk()->assertJsonPath('data.is_watching', false);

        $this->assertSame(0, $this->rows());
    }

    public function test_each_viewer_sees_only_their_own_watch_status(): void
    {
        $auction = Auction::factory()->live()->create();
        $this->watch($auction);

        $this->actingAs($this->bidder)->getJson("/api/auctions/{$auction->id}")->assertJsonPath('data.is_watching', true);
        $this->actingAs(User::factory()->create())->getJson("/api/auctions/{$auction->id}")->assertJsonPath('data.is_watching', false);
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/auctions/{$auction->id}")
            ->assertJsonPath('data.is_watching', false)
            ->assertJsonMissingPath('data.watchers')
            ->assertJsonMissingPath('data.watchers_count');
    }

    public function test_the_list_marks_watched_auctions_for_the_viewer(): void
    {
        $watched = Auction::factory()->live()->create();
        $other = Auction::factory()->live()->create();
        $this->watch($watched);

        $flags = collect($this->actingAs($this->bidder)->getJson('/api/auctions')->assertOk()->json('data'))
            ->pluck('is_watching', 'id');

        $this->assertTrue($flags[$watched->id]);
        $this->assertFalse($flags[$other->id]);
    }

    public function test_the_watching_filter_lists_only_my_watchlist(): void
    {
        $mine = Auction::factory()->live()->create();
        $finished = Auction::factory()->completed()->create();
        $theirs = Auction::factory()->live()->create();
        Auction::factory()->live()->create();
        $this->watch($mine);
        $this->watch($finished);
        $this->watch($theirs, User::factory()->create());

        $ids = collect($this->actingAs($this->bidder)->getJson('/api/auctions?watching=1')->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$mine->id, $finished->id], $ids->all());
    }

    public function test_the_watching_filter_needs_a_login(): void
    {
        $this->getJson('/api/auctions?watching=1')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_drafts_and_missing_auctions_cannot_be_watched(): void
    {
        $this->watch(Auction::factory()->create())->assertNotFound()->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');
        $this->watch(999_999)->assertNotFound()->assertJsonPath('error.code', 'AUCTION_NOT_FOUND');

        $this->assertSame(0, $this->rows());
    }

    public function test_admins_and_guests_cannot_watch(): void
    {
        $auction = Auction::factory()->live()->create();

        $this->watch($auction, User::factory()->admin()->create())->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->putJson("/api/auctions/{$auction->id}/watch")->assertUnauthorized();

        $this->assertSame(0, $this->rows());
    }
}
