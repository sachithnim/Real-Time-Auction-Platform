<?php

namespace App\Services\Bidding;

use App\Enums\BidType;

/**
 * A visible bid the resolution wants inserted into the history.
 */
final class PlannedBid
{
    public function __construct(
        public readonly int $userId,
        public readonly int $amount,
        public readonly BidType $type,
    ) {}
}
