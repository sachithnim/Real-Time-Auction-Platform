<?php

namespace App\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One entry in a bidder's notification inbox (the bell). `type` is the event's
 * short name (Outbid, AuctionWon…) and `data` its payload, the same as the live
 * pop-up, so both say the same thing.
 */
class Notice extends DatabaseNotification
{
    use MassPrunable;

    /** Kept for this many days after its last update. */
    public const KEEP_DAYS = 90;

    public function prunable()
    {
        return static::where('updated_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
