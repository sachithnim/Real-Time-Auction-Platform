<?php

namespace App\Services\Bidding;

use App\Enums\BidType;
use DateTimeInterface;

/**
 * One bidder competing in a resolution: how far they will go (max) and when
 * they committed to it (setAt, used to break ties: earlier wins).
 */
final class ProxyCandidate
{
    public function __construct(
        public readonly int $userId,
        public readonly int $max,
        public readonly DateTimeInterface $setAt,
        public readonly BidType $type,
        /** Final tie-breaker when two maximums were set at the same instant (lower wins). */
        public readonly int $sequence = 0,
    ) {}
}
