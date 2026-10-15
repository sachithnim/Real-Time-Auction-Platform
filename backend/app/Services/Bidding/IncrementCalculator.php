<?php

namespace App\Services\Bidding;

use App\Models\Auction;
use InvalidArgumentException;

/**
 * Pure bid-increment arithmetic. Holds the tier table in memory and never
 * touches the database, so it is unit-testable and safe to call under a lock.
 *
 * Effective increment = max(tier increment for the price, auction minimum increment):
 * the tiers are the platform-wide floor and an admin can only make one auction stricter.
 */
class IncrementCalculator
{
    /** @var list<array{from_amount: int, increment: int}> tiers sorted by from_amount, highest first */
    private array $tiers;

    /**
     * @param  iterable<array{from_amount: int, to_amount?: int|null, increment: int}>  $tiers
     */
    public function __construct(iterable $tiers)
    {
        $sorted = [];
        foreach ($tiers as $tier) {
            $sorted[] = ['from_amount' => (int) $tier['from_amount'], 'increment' => (int) $tier['increment']];
        }

        if ($sorted === []) {
            throw new InvalidArgumentException('At least one bid increment tier is required.');
        }

        usort($sorted, fn (array $a, array $b) => $b['from_amount'] <=> $a['from_amount']);
        $this->tiers = $sorted;
    }

    /**
     * The tier whose range contains the price: the tier with the largest
     * from_amount not above it. Integer prices leave no gaps between tiers.
     */
    public function tierIncrement(int $price): int
    {
        foreach ($this->tiers as $tier) {
            if ($price >= $tier['from_amount']) {
                return $tier['increment'];
            }
        }

        // Price below the lowest tier: use the lowest tier.
        return end($this->tiers)['increment'];
    }

    public function incrementFor(int $price, Auction $auction): int
    {
        return max($this->tierIncrement($price), $auction->min_increment);
    }

    /**
     * Lowest acceptable next bid: the starting price while there are no bids,
     * otherwise the current price plus the increment for that price.
     */
    public function minNextBid(Auction $auction): int
    {
        if ($auction->current_price === null) {
            return $auction->starting_price;
        }

        return $auction->current_price + $this->incrementFor($auction->current_price, $auction);
    }
}
