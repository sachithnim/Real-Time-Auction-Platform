<?php

namespace App\Services\Bidding;

use App\Enums\BidType;
use Closure;

/**
 * Pure proxy-bidding arithmetic: no database, no clock. BidService calls it
 * under the auction row lock with the current state and applies the result.
 *
 * A challenger (a new manual bid, or a new/raised auto-bid maximum) meets the
 * strongest defender among the other bidders: active auto-bid maximums, plus the
 * current leader's own price when they lead with a manual bid. Defenders are
 * ranked by max DESC, then setAt ASC (whoever committed to the amount first).
 *
 *  - Challenger's max beats the defender's: the challenger leads. A manual bid
 *    is taken literally (its amount); an auto-bid pays only what is needed,
 *    min(own max, defender max + increment(defender max)).
 *  - Otherwise the defender keeps (or takes) the lead: the challenger's bid is
 *    shown at its full amount and the defender answers automatically with
 *    min(defender max, challenger max + increment(challenger max)), or exactly
 *    the tied amount when both maximums are equal (earlier commitment wins).
 *
 * The caller has already checked that the challenger's amount is at least the
 * minimum next bid, so every resulting price is valid.
 */
class ProxyBidResolver
{
    /**
     * @param  list<ProxyCandidate>  $defenders  other bidders only (never the challenger)
     * @param  Closure(int): int  $incrementFor  increment that applies at a given price
     */
    public function resolve(
        ProxyCandidate $challenger,
        array $defenders,
        ?int $currentPrice,
        int $startingPrice,
        Closure $incrementFor,
    ): ProxyResolution {
        $best = $this->strongest($defenders);

        if ($best === null) {
            // Nobody to compete with: a manual bid stands at its amount; an
            // auto-bid opens at the starting price (or holds the current one).
            $price = $challenger->type === BidType::Manual
                ? $challenger->max
                : ($currentPrice ?? $startingPrice);

            return new ProxyResolution($challenger->userId, $price, [
                new PlannedBid($challenger->userId, $price, $challenger->type),
            ]);
        }

        if ($challenger->max > $best->max) {
            $price = $challenger->type === BidType::Manual
                ? $challenger->max
                : min($challenger->max, $best->max + $incrementFor($best->max));

            return new ProxyResolution($challenger->userId, $price, [
                new PlannedBid($challenger->userId, $price, $challenger->type),
            ]);
        }

        // The defender holds: equal maximums are won by the earlier commitment.
        $price = $best->max === $challenger->max
            ? $best->max
            : min($best->max, $challenger->max + $incrementFor($challenger->max));

        return new ProxyResolution($best->userId, $price, [
            new PlannedBid($challenger->userId, $challenger->max, $challenger->type),
            new PlannedBid($best->userId, $price, BidType::Auto),
        ]);
    }

    /**
     * @param  list<ProxyCandidate>  $candidates
     */
    private function strongest(array $candidates): ?ProxyCandidate
    {
        if ($candidates === []) {
            return null;
        }

        usort($candidates, fn (ProxyCandidate $a, ProxyCandidate $b) => [$b->max, $a->setAt, $a->sequence]
            <=> [$a->max, $b->setAt, $b->sequence]);

        return $candidates[0];
    }
}
