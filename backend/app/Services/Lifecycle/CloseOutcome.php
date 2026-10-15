<?php

namespace App\Services\Lifecycle;

use App\Models\Auction;
use DateTimeInterface;

/**
 * What a close attempt did: closed the auction, found it not yet due
 * (extended), or skipped it because it is no longer live.
 */
final class CloseOutcome
{
    private function __construct(
        public readonly string $status,
        public readonly ?Auction $auction = null,
        public readonly ?DateTimeInterface $endAt = null,
    ) {}

    public static function closed(Auction $auction): self
    {
        return new self('CLOSED', $auction);
    }

    public static function notDue(DateTimeInterface $endAt): self
    {
        return new self('NOT_DUE', endAt: $endAt);
    }

    public static function skipped(): self
    {
        return new self('SKIPPED');
    }

    public function wasClosed(): bool
    {
        return $this->status === 'CLOSED';
    }

    public function isNotDue(): bool
    {
        return $this->status === 'NOT_DUE';
    }
}
