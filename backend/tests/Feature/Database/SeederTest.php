<?php

namespace Tests\Feature\Database;

use App\Enums\AuctionStatus;
use App\Enums\UserRole;
use App\Models\Auction;
use App\Models\BidIncrementRule;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_an_admin_account_that_can_log_in(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@auction.test')->firstOrFail();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue(Hash::check('password', $admin->password));
    }

    public function test_seeds_demo_bidders(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(5, User::where('role', UserRole::Bidder)->count());
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

    public function test_seeds_a_demo_auction_in_every_lifecycle_state_except_cancelled(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([AuctionStatus::Draft, AuctionStatus::Scheduled, AuctionStatus::Live, AuctionStatus::Completed] as $status) {
            $this->assertTrue(Auction::where('status', $status)->exists(), "No {$status->value} demo auction");
        }
    }

    public function test_seeding_twice_does_not_duplicate_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(6, User::count());
        $this->assertSame(4, BidIncrementRule::count());
        $this->assertSame(5, Auction::count());
    }
}
