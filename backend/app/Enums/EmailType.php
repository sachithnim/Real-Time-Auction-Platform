<?php

namespace App\Enums;

/** The notification emails a bidder can switch on or off, one by one. */
enum EmailType: string
{
    case Outbid = 'OUTBID';
    case MaxReached = 'MAX_REACHED';
    case Won = 'WON';
    case Reminders = 'REMINDERS';
    case Receipts = 'RECEIPTS';

    /** How the type is named in emails ("Unsubscribe from outbid emails"). */
    public function label(): string
    {
        return match ($this) {
            self::Outbid => 'outbid',
            self::MaxReached => 'maximum reached',
            self::Won => 'auction won',
            self::Reminders => 'watchlist reminder',
            self::Receipts => 'payment receipt',
        };
    }
}
