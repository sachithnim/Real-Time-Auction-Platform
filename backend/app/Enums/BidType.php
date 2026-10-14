<?php

namespace App\Enums;

enum BidType: string
{
    /** Placed directly by the bidder. */
    case Manual = 'MANUAL';

    /** Placed by the system on the bidder's behalf from their private maximum. */
    case Auto = 'AUTO';
}
