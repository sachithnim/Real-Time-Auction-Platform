<?php

namespace App\Console\Commands;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Services\AuctionLifecycleService;
use Illuminate\Console\Command;

/**
 * Safety net for lost or delayed start jobs: opens every scheduled auction
 * whose start time has passed. Running it while jobs also run is safe.
 */
class StartDueAuctions extends Command
{
    protected $signature = 'auctions:start-due';

    protected $description = 'Start scheduled auctions whose start time has passed';

    public function handle(AuctionLifecycleService $lifecycle): int
    {
        $started = 0;

        Auction::where('status', AuctionStatus::Scheduled)
            ->where('start_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use ($lifecycle, &$started) {
                $started += $lifecycle->start($id) ? 1 : 0;
            });

        $this->info("Started {$started} auction(s).");

        return self::SUCCESS;
    }
}
