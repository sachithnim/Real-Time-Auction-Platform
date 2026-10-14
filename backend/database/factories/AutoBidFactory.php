<?php

namespace Database\Factories;

use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AutoBid>
 */
class AutoBidFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'auction_id' => Auction::factory()->live(),
            'user_id' => User::factory(),
            'max_amount' => fake()->numberBetween(100, 1000) * 100,
            'is_active' => true,
            'max_set_at' => now(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
