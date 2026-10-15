<?php

namespace Tests\Feature\Bidding;

use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Uses real commits (not a wrapping test transaction) so that a second
 * database connection can hold the auction row lock while the API bids.
 */
class BidLockConflictTest extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected $exceptTables = ['migrations', 'bid_increment_rules'];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auction.bid_lock_wait_seconds' => 1,
            'auction.bid_transaction_attempts' => 2,
            'database.connections.lock_holder' => config('database.connections.mysql'),
        ]);
    }

    protected function tearDown(): void
    {
        DB::connection('lock_holder')->rollBack();
        DB::purge('lock_holder');
        // Leave no committed rows behind for the other test classes.
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_bid_that_cannot_get_the_lock_returns_bid_conflict_retry_and_can_be_retried(): void
    {
        $auction = Auction::factory()->live()->create(['starting_price' => 20_000, 'min_increment' => 500]);
        $bidder = User::factory()->create();
        $key = (string) Str::uuid();

        // Another transaction (e.g. a slow concurrent bid) holds the auction row lock.
        $holder = DB::connection('lock_holder');
        $holder->beginTransaction();
        $holder->select('SELECT id FROM auctions WHERE id = ? FOR UPDATE', [$auction->id]);

        $started = microtime(true);
        $this->actingAs($bidder)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/auctions/{$auction->id}/bids", ['amount' => 20_000])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BID_CONFLICT_RETRY');
        $elapsed = microtime(true) - $started;

        // Two attempts of ~1 second each: it waited, retried, then gave up.
        $this->assertGreaterThanOrEqual(1.9, $elapsed);
        $this->assertSame(0, Bid::count());
        $this->assertNull($auction->fresh()->current_price);
        $this->assertSame(0, AuctionEvent::count());

        // Lock released: the client retries with the same key and succeeds.
        $holder->rollBack();

        $this->actingAs($bidder)
            ->withHeader('Idempotency-Key', $key)
            ->postJson("/api/auctions/{$auction->id}/bids", ['amount' => 20_000])
            ->assertCreated()
            ->assertJsonPath('data.auction.current_price', 20_000);

        $this->assertSame(1, Bid::count());
    }
}
