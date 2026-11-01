<?php

namespace Tests\Feature\Database;

use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\AuctionImage;
use App\Models\BidIncrementRule;
use App\Models\User;
use App\Services\AuctionImageService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAuctionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The demo catalogue stores photos: keep them out of the real storage folder.
        Storage::fake(AuctionImageService::DISK);
    }

    public function test_seeds_an_admin_account_that_can_log_in(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@auction.test')->firstOrFail();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('password', $admin->password));
    }

    public function test_default_increment_tiers_exist_without_running_seeders(): void
    {
        // Shipped by a data migration, so production and tests always have them.
        $this->assertSame(4, BidIncrementRule::count());
    }

    public function test_seeds_demo_bidders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, User::where('role', UserRole::Bidder)->count());
    }

    public function test_demo_bidders_have_real_looking_names_not_pseudonyms(): void
    {
        $this->seed(DatabaseSeeder::class);

        $names = User::where('role', UserRole::Bidder)->orderBy('email')->pluck('name', 'email')->all();

        $this->assertSame([
            'bidder1@auction.test' => 'Asha Perera',
            'bidder2@auction.test' => 'Nimal Silva',
            'bidder3@auction.test' => 'Kavindi Fernando',
            'bidder4@auction.test' => 'Ruwan Jayasinghe',
            'bidder5@auction.test' => 'Dilini Wickramasinghe',
        ], $names);
        // A name like "Bidder 2" would be confused with the pseudonyms bidders see.
        $this->assertSame(0, User::where('name', 'like', 'Bidder %')->count());
    }

    public function test_seeds_the_assignment_increment_tiers(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tiers = BidIncrementRule::orderBy('from_amount')
            ->get(['from_amount', 'to_amount', 'increment'])
            ->toArray();

        $this->assertSame([
            ['from_amount' => 0, 'to_amount' => 10_000, 'increment' => 100],
            ['from_amount' => 10_001, 'to_amount' => 50_000, 'increment' => 500],
            ['from_amount' => 50_001, 'to_amount' => 100_000, 'increment' => 1_000],
            ['from_amount' => 100_001, 'to_amount' => null, 'increment' => 2_500],
        ], $tiers);
    }

    public function test_seeds_a_demo_auction_in_every_lifecycle_state(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (AuctionStatus::cases() as $status) {
            $this->assertTrue(Auction::where('status', $status)->exists(), "No {$status->value} demo auction");
        }
        $this->assertEqualsCanonicalizing(
            [AuctionResult::Sold, AuctionResult::ReserveNotMet, AuctionResult::NoBids],
            Auction::whereNotNull('result')->get()->pluck('result')->unique()->values()->all(),
        );
    }

    public function test_every_demo_auction_has_photos_on_disk(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, Auction::doesntHave('images')->count());
        foreach (AuctionImage::all() as $image) {
            Storage::disk(AuctionImageService::DISK)->assertExists($image->path);
        }
    }

    public function test_demo_bidding_is_consistent_with_the_bid_history(): void
    {
        $this->seed(DatabaseSeeder::class);

        $withBids = Auction::has('bids')->get();
        $this->assertGreaterThan(5, $withBids->count());
        foreach ($withBids as $auction) {
            // Placed through the real bid engine: the price is the top visible bid and its bidder leads.
            $top = $auction->bids()->reorder()->orderByDesc('id')->first();
            $this->assertSame($top->amount, $auction->current_price, $auction->title);
            $this->assertSame($top->user_id, $auction->leader_id, $auction->title);
        }
        // The anti-sniping example: a late bid extended one finished auction.
        $this->assertTrue(Auction::where('extensions_count', '>', 0)->exists());
    }

    public function test_seeding_twice_does_not_duplicate_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, User::count());
        $this->assertSame(4, BidIncrementRule::count());
        $this->assertSame(DemoAuctionSeeder::COUNT, Auction::count());
    }
}
