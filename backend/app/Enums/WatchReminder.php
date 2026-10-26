<?php

namespace App\Enums;

/** The reminders a watcher can receive about an auction, each at most once. */
enum WatchReminder: string
{
    case StartingSoon = 'STARTING_SOON';
    case EndingSoon = 'ENDING_SOON';
}
