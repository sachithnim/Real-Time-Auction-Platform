<?php

namespace App\Jobs;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Services\AuctionLifecycleService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Opens one scheduled auction at its start time. Dispatched (delayed) when the
 * auction is scheduled; a frequent sweep is the safety net. Starting is
 * idempotent, so duplicates are harmless.
 */
class StartAuctionJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 60];

    public int $uniqueFor;

    public function __construct(public readonly int $auctionId, int $secondsUntilRun = 0)
    {
        $this->uniqueFor = max(60, $secondsUntilRun + 300);
    }

    public static function scheduleFor(Auction $auction): void
    {
        $seconds = max(0, (int) ceil(now()->diffInSeconds($auction->start_at, false)));

        static::dispatch($auction->id, $seconds)->delay($auction->start_at)->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->auctionId;
    }

    public function handle(AuctionLifecycleService $lifecycle): void
    {
        if ($lifecycle->start($this->auctionId)) {
            return;
        }

        // Picked up early (e.g. clock drift): try again at the start time.
        $auction = Auction::find($this->auctionId);
        if ($auction?->status === AuctionStatus::Scheduled && now()->lt($auction->start_at)) {
            static::scheduleFor($auction);
        }
    }
}
