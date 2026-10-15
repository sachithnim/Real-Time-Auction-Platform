<?php

namespace App\Jobs;

use App\Models\Auction;
use App\Services\Lifecycle\AuctionCloser;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Closes one auction at its end time. Dispatched (delayed) when the auction
 * goes live; a minute-by-minute sweep is the safety net if it is ever lost.
 *
 * ShouldBeUniqueUntilProcessing stops duplicate pending jobs per auction, and
 * releases the lock as soon as the job starts so it can re-schedule itself.
 * Correctness does not depend on it: AuctionCloser re-checks status under lock.
 */
class CloseAuctionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 60];

    /** Seconds the uniqueness lock may live: long enough to cover the delay. */
    public int $uniqueFor;

    public function __construct(public readonly int $auctionId, int $secondsUntilRun = 0)
    {
        $this->uniqueFor = max(60, $secondsUntilRun + 300);
    }

    /** Queue a close for the auction's current end time. */
    public static function scheduleFor(Auction $auction): void
    {
        $seconds = max(0, (int) ceil(now()->diffInSeconds($auction->end_at, false)));

        static::dispatch($auction->id, $seconds)->delay($auction->end_at)->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->auctionId;
    }

    public function handle(AuctionCloser $closer): void
    {
        $outcome = $closer->close($this->auctionId);

        if ($outcome->isNotDue()) {
            // Anti-sniping moved the end: try again at the new end time.
            static::scheduleFor(Auction::findOrFail($this->auctionId));
        }
    }
}
