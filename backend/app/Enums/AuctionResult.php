<?php

namespace App\Enums;

/**
 * Outcome of a COMPLETED auction (null for every other status).
 */
enum AuctionResult: string
{
    case Sold = 'SOLD';
    case ReserveNotMet = 'RESERVE_NOT_MET';
    case NoBids = 'NO_BIDS';
}
