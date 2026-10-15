<?php

namespace Tests\Unit\Bidding;

use App\Models\Auction;
use App\Services\Bidding\IncrementCalculator;
use Database\Seeders\BidIncrementRuleSeeder;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IncrementCalculatorTest extends TestCase
{
    private function calculator(): IncrementCalculator
    {
        return new IncrementCalculator(BidIncrementRuleSeeder::TIERS);
    }

    private function auction(int $minIncrement = 1, int $startingPrice = 1_000, ?int $currentPrice = null): Auction
    {
        return (new Auction)->forceFill([
            'min_increment' => $minIncrement,
            'starting_price' => $startingPrice,
            'current_price' => $currentPrice,
        ]);
    }

    /**
     * Both sides of every tier boundary from the assignment table.
     *
     * @return array<string, array{int, int}>
     */
    public static function tierBoundaries(): array
    {
        return [
            'zero' => [0, 100],
            'inside first tier' => [5_000, 100],
            'last price of first tier (10,000)' => [10_000, 100],
            'first price of second tier (10,001)' => [10_001, 500],
            'last price of second tier (50,000)' => [50_000, 500],
            'first price of third tier (50,001)' => [50_001, 1_000],
            'last price of third tier (100,000)' => [100_000, 1_000],
            'first price of top tier (100,001)' => [100_001, 2_500],
            'far above the top tier' => [50_000_000, 2_500],
        ];
    }

    #[DataProvider('tierBoundaries')]
    public function test_tier_increment_on_both_sides_of_each_boundary(int $price, int $expected): void
    {
        $this->assertSame($expected, $this->calculator()->tierIncrement($price));
    }

    public function test_auction_minimum_increment_wins_when_larger_than_the_tier(): void
    {
        // Tier says 100 at 5,000, but this auction requires at least 500.
        $this->assertSame(500, $this->calculator()->incrementFor(5_000, $this->auction(minIncrement: 500)));
    }

    public function test_tier_increment_wins_when_larger_than_the_auction_minimum(): void
    {
        // Tier says 2,500 above 100,000; the auction's 100 is only a floor.
        $this->assertSame(2_500, $this->calculator()->incrementFor(150_000, $this->auction(minIncrement: 100)));
    }

    public function test_min_next_bid_is_the_starting_price_when_there_are_no_bids(): void
    {
        $this->assertSame(20_000, $this->calculator()->minNextBid($this->auction(startingPrice: 20_000)));
    }

    public function test_assignment_example_current_25000_increment_500_requires_25500(): void
    {
        $auction = $this->auction(minIncrement: 500, startingPrice: 10_000, currentPrice: 25_000);

        $this->assertSame(25_500, $this->calculator()->minNextBid($auction));
    }

    public function test_min_next_bid_uses_the_increment_of_the_current_price_tier(): void
    {
        $calculator = $this->calculator();

        $this->assertSame(10_100, $calculator->minNextBid($this->auction(currentPrice: 10_000)));
        $this->assertSame(10_501, $calculator->minNextBid($this->auction(currentPrice: 10_001)));
        $this->assertSame(101_000, $calculator->minNextBid($this->auction(currentPrice: 100_000)));
        $this->assertSame(102_501, $calculator->minNextBid($this->auction(currentPrice: 100_001)));
    }

    public function test_tier_order_in_the_input_does_not_matter(): void
    {
        $calculator = new IncrementCalculator(array_reverse(BidIncrementRuleSeeder::TIERS));

        $this->assertSame(500, $calculator->tierIncrement(10_001));
        $this->assertSame(100, $calculator->tierIncrement(10_000));
    }

    public function test_requires_at_least_one_tier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IncrementCalculator([]);
    }
}
