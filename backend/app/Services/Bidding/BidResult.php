<?php

namespace App\Services\Bidding;

use App\Models\Auction;
use App\Models\Bid;

/**
 * Outcome of an accepted bid: the bid row and the auction state right after it.
 */
final class BidResult
{
    public function __construct(
        public readonly Bid $bid,
        public readonly Auction $auction,
        public readonly int $minNextBid,
    ) {}
}
