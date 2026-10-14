<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The database is the last line of defence: these rules hold even if
 * application code is wrong. Raw inserts bypass models on purpose.
 */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminId = User::factory()->admin()->create()->id;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertAuction(array $overrides = []): int
    {
        return DB::table('auctions')->insertGetId(array_merge([
            'title' => 'Vintage watch',
            'description' => 'A watch.',
            'starting_price' => 10_000,
            'reserve_price' => 20_000,
            'min_increment' => 100,
            'start_at' => '2030-01-01 10:00:00',
            'end_at' => '2030-01-01 12:00:00',
            'original_end_at' => '2030-01-01 12:00:00',
            'anti_snipe_seconds' => 120,
            'extension_seconds' => 120,
            'max_extensions' => 3,
            'status' => 'DRAFT',
            'created_by' => $this->adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_a_valid_auction_is_accepted(): void
    {
        $this->assertGreaterThan(0, $this->insertAuction());
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function invalidAuctions(): array
    {
        return [
            'end before start' => [['end_at' => '2030-01-01 09:00:00']],
            'end equal to start' => [['end_at' => '2030-01-01 10:00:00']],
            'reserve below starting price' => [['reserve_price' => 9_999]],
            'zero increment' => [['min_increment' => 0]],
            'extensions above maximum' => [['max_extensions' => 3, 'extensions_count' => 4]],
            'price below starting price' => [['current_price' => 5_000]],
            'negative money' => [['starting_price' => -1]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidAuctions')]
    public function test_database_rejects_an_invalid_auction(array $overrides): void
    {
        $this->expectException(QueryException::class);

        $this->insertAuction($overrides);
    }

    public function test_reserve_price_is_optional(): void
    {
        $this->assertGreaterThan(0, $this->insertAuction(['reserve_price' => null]));
    }

    public function test_a_bidder_has_at_most_one_auto_bid_per_auction(): void
    {
        $auctionId = $this->insertAuction();
        $bidderId = User::factory()->create()->id;
        $row = [
            'auction_id' => $auctionId,
            'user_id' => $bidderId,
            'max_amount' => 20_000,
            'max_set_at' => now(),
        ];

        DB::table('auto_bids')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('auto_bids')->insert(array_merge($row, ['max_amount' => 25_000]));
    }

    public function test_an_idempotency_key_is_unique_per_user(): void
    {
        $userId = User::factory()->create()->id;
        $row = [
            'user_id' => $userId,
            'key' => 'b3c1f6a2-7d1e-4c55-9d0a-1f2e3d4c5b6a',
            'request_hash' => str_repeat('a', 64),
            'status' => 'PROCESSING',
        ];

        DB::table('idempotency_keys')->insert($row);

        $this->expectException(QueryException::class);
        DB::table('idempotency_keys')->insert($row);
    }

    public function test_bids_must_belong_to_an_existing_auction(): void
    {
        $this->expectException(QueryException::class);

        DB::table('bids')->insert([
            'auction_id' => 999_999,
            'user_id' => $this->adminId,
            'amount' => 10_000,
            'type' => 'MANUAL',
            'created_at' => now(),
        ]);
    }

    public function test_increment_rules_reject_inverted_ranges(): void
    {
        $this->expectException(QueryException::class);

        DB::table('bid_increment_rules')->insert([
            'from_amount' => 10_000,
            'to_amount' => 5_000,
            'increment' => 100,
        ]);
    }
}
