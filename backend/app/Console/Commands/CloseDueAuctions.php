<?php

namespace App\Console\Commands;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Console\Command;

/**
 * Safety net for lost or delayed close jobs: closes every live auction whose
 * end time has passed. Running it while jobs also run is safe.
 */
class CloseDueAuctions extends Command
{
    protected $signature = 'auctions:close-due';

    protected $description = 'Close live auctions whose end time has passed';

    public function handle(AuctionCloser $closer): int
    {
        $closed = 0;

        Auction::where('status', AuctionStatus::Live)
            ->where('end_at', '<=', now())
            ->pluck('id')
            ->each(function (int $id) use ($closer, &$closed) {
                $closed += $closer->close($id)->wasClosed() ? 1 : 0;
            });

        $this->info("Closed {$closed} auction(s).");

        return self::SUCCESS;
    }
}
