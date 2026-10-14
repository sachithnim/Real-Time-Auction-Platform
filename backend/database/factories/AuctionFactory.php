<?php

namespace Database\Factories;

use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Auction>
 */
class AuctionFactory extends Factory
{
    /**
     * A DRAFT auction starting tomorrow and running for two hours.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startingPrice = fake()->numberBetween(10, 500) * 100;
        $startAt = now()->addDay()->startOfMinute();
        $endAt = $startAt->copy()->addHours(2);

        return [
            'title' => fake()->words(3, true),
            'description' => fake()->paragraph(),
            'starting_price' => $startingPrice,
            // Follows any overridden starting price so the reserve is never below it.
            'reserve_price' => fn (array $attributes) => $attributes['starting_price'] * 2,
            'min_increment' => 100,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'original_end_at' => $endAt,
            'anti_snipe_seconds' => 120,
            'extension_seconds' => 120,
            'max_extensions' => 3,
            'status' => AuctionStatus::Draft,
            'created_by' => User::factory()->admin(),
        ];
    }

    public function scheduled(): static
    {
        return $this->state(['status' => AuctionStatus::Scheduled]);
    }

    /** Started an hour ago, ends in an hour. */
    public function live(): static
    {
        return $this->state(function () {
            $startAt = now()->subHour()->startOfSecond();
            $endAt = now()->addHour()->startOfSecond();

            return [
                'status' => AuctionStatus::Live,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'original_end_at' => $endAt,
                'started_at' => $startAt,
            ];
        });
    }

    /** Finished an hour ago without bids. */
    public function completed(AuctionResult $result = AuctionResult::NoBids): static
    {
        return $this->state(function () use ($result) {
            $startAt = now()->subHours(3)->startOfSecond();
            $endAt = now()->subHour()->startOfSecond();

            return [
                'status' => AuctionStatus::Completed,
                'result' => $result,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'original_end_at' => $endAt,
                'started_at' => $startAt,
                'ended_at' => $endAt,
            ];
        });
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => AuctionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function withoutReserve(): static
    {
        return $this->state(['reserve_price' => null]);
    }
}
