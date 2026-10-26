<?php

namespace App\Console\Commands;

use App\Services\Lifecycle\WatchReminders;
use Illuminate\Console\Command;

/**
 * Tells bidders that a watched auction is about to start or end. Each reminder
 * goes out once, so running this often (or twice) is safe.
 */
class RemindWatchers extends Command
{
    protected $signature = 'auctions:remind-watchers';

    protected $description = 'Remind watchers of auctions that start or end soon';

    public function handle(WatchReminders $reminders): int
    {
        $this->info("Sent {$reminders->sendDue()} reminder(s).");

        return self::SUCCESS;
    }
}
