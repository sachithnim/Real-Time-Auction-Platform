<?php

namespace App\Enums;

/**
 * Lifecycle: DRAFT → SCHEDULED → LIVE → COMPLETED, plus CANCELLED from
 * DRAFT, SCHEDULED or LIVE. This enum is the single source of truth for
 * which transitions are allowed.
 */
enum AuctionStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Live = 'LIVE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Scheduled, self::Cancelled],
            self::Scheduled => [self::Live, self::Cancelled],
            self::Live => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    /** Only drafts may have their details changed. */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
