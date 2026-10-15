<?php

namespace App\Services\Bidding;

/**
 * Outcome of one resolution: who leads, at what price, and the visible bids
 * to insert (in order) to get there.
 */
final class ProxyResolution
{
    /**
     * @param  list<PlannedBid>  $bids
     */
    public function __construct(
        public readonly int $leaderId,
        public readonly int $price,
        public readonly array $bids,
    ) {}
}
