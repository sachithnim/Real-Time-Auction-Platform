<?php

namespace Database\Factories;

use App\Enums\BidType;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds raw bid rows for tests. Real bids are only ever created by BidService.
 *
 * @extends Factory<Bid>
 */
class BidFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'auction_id' => Auction::factory()->live(),
            'user_id' => User::factory(),
            'amount' => fake()->numberBetween(10, 500) * 100,
            'type' => BidType::Manual,
        ];
    }

    public function auto(): static
    {
        return $this->state(['type' => BidType::Auto]);
    }
}
