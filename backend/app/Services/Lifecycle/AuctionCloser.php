<?php

namespace App\Services\Lifecycle;

use App\Enums\AuctionEventType;
use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Services\AuctionEventRecorder;
use App\Support\ServerTime;
use Illuminate\Support\Facades\DB;

/**
 * Determines the final result of an auction. Safe to run any number of times
 * and concurrently with bids: it holds the auction row lock, does nothing unless
 * the auction is still LIVE, and refuses to close before the (possibly
 * extended) end time.
 */
class AuctionCloser
{
    public function __construct(private readonly AuctionEventRecorder $events) {}

    public function close(int $auctionId): CloseOutcome
    {
        return DB::transaction(function () use ($auctionId) {
            // Same lock as bids, so a bid and the close can never interleave.
            $auction = Auction::whereKey($auctionId)->lockForUpdate()->first();

            // Already closed, cancelled or gone: a repeated run is a no-op.
            if ($auction === null || $auction->status !== AuctionStatus::Live) {
                return CloseOutcome::skipped();
            }

            // Extended by anti-sniping since this run was scheduled.
            if (now()->lt($auction->end_at)) {
                return CloseOutcome::notDue($auction->end_at);
            }

            $this->finish($auction);

            return CloseOutcome::closed($auction);
        });
    }

    /** Must run under the auction lock. */
    private function finish(Auction $auction): void
    {
        // The leader and price on the locked row already encode every rule
        // (increments, proxy resolution, ties), so they are the highest valid bid.
        $highestBid = $auction->current_price;
        $highestBidderId = $auction->leader_id;

        $result = match (true) {
            $highestBidderId === null => AuctionResult::NoBids,
            $auction->reserve_price !== null && $highestBid < $auction->reserve_price => AuctionResult::ReserveNotMet,
            default => AuctionResult::Sold,
        };

        $auction->forceFill([
            'status' => AuctionStatus::Completed,
            'result' => $result,
            'ended_at' => now(),
            'winner_id' => $result === AuctionResult::Sold ? $highestBidderId : null,
            'final_price' => $result === AuctionResult::Sold ? $highestBid : null,
        ])->save();

        $this->events->record($auction, AuctionEventType::AuctionEnded, null, [
            'result' => $result->value,
            'end_at' => ServerTime::format($auction->end_at),
            'original_end_at' => ServerTime::format($auction->original_end_at),
            'extensions_count' => $auction->extensions_count,
            'ended_at' => ServerTime::format($auction->ended_at),
            'highest_bid' => $highestBid,
            'highest_bidder_id' => $highestBidderId,
            'bids_count' => $auction->bids()->count(),
        ]);

        if ($result === AuctionResult::Sold) {
            $this->events->record($auction, AuctionEventType::WinnerSelected, null, [
                'winner_id' => $highestBidderId,
                'final_price' => $highestBid,
                'reserve_price' => $auction->reserve_price,
            ]);
        }

        if ($result === AuctionResult::ReserveNotMet) {
            $this->events->record($auction, AuctionEventType::ReserveNotMet, null, [
                'highest_bid' => $highestBid,
                'highest_bidder_id' => $highestBidderId,
                'reserve_price' => $auction->reserve_price,
            ]);
        }
    }
}
