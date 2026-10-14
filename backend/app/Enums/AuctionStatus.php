<?php

namespace App\Enums;

/**
 * Lifecycle: DRAFT → SCHEDULED → LIVE → COMPLETED, plus CANCELLED.
 */
enum AuctionStatus: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Live = 'LIVE';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
}
