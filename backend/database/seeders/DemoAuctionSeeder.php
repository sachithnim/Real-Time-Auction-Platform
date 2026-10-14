<?php

namespace Database\Seeders;

use App\Enums\AuctionResult;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One auction in each lifecycle state so every screen has something to show.
 * Skipped when auctions already exist, so re-seeding never duplicates them.
 */
class DemoAuctionSeeder extends Seeder
{
    public function run(): void
    {
        if (Auction::exists()) {
            return;
        }

        $admin = User::where('email', UserSeeder::ADMIN_EMAIL)->firstOrFail();
        $factory = Auction::factory()->for($admin, 'creator');

        $factory->create([
            'title' => 'Antique Brass Telescope',
            'description' => 'Hand-polished 19th century brass telescope with leather grip. Draft: not yet published.',
            'starting_price' => 15_000,
            'reserve_price' => 30_000,
        ]);

        $factory->scheduled()->create([
            'title' => 'Signed Cricket Bat',
            'description' => 'Bat signed by the 1996 World Cup winning squad, with certificate of authenticity.',
            'starting_price' => 50_000,
            'reserve_price' => 120_000,
            'start_at' => now()->addMinutes(30)->startOfMinute(),
            'end_at' => now()->addMinutes(90)->startOfMinute(),
            'original_end_at' => now()->addMinutes(90)->startOfMinute(),
        ]);

        $factory->live()->create([
            'title' => 'Vintage Ceylon Tea Chest',
            'description' => 'Teak tea chest from a Nuwara Eliya estate, circa 1920. Reserve applies.',
            'starting_price' => 20_000,
            'reserve_price' => 50_000,
            'min_increment' => 500,
        ]);

        $factory->live()->withoutReserve()->create([
            'title' => 'Hand-Carved Kandyan Mask',
            'description' => 'Traditional Kolam mask carved from kaduru wood. No reserve.',
            'starting_price' => 5_000,
            'min_increment' => 100,
        ]);

        $factory->completed(AuctionResult::NoBids)->create([
            'title' => 'Mid-Century Rosewood Desk',
            'description' => 'Rosewood writing desk with brass fittings. Ended without bids.',
            'starting_price' => 80_000,
            'reserve_price' => 100_000,
        ]);
    }
}
