<?php

namespace App\Services\Bidding;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Enums\BidType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use App\Services\AuctionEventRecorder;
use App\Support\ServerTime;
use Closure;
use DateTimeInterface;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only place bids are accepted.
 *
 * Every bid runs in ONE transaction that starts by locking the auction row
 * (SELECT ... FOR UPDATE). Bids on the same auction are therefore processed one
 * at a time in lock-acquisition order; bids on different auctions never block
 * each other. Validation, the bid insert, the price/leader update and the audit
 * events all happen under that lock, so no two bids can see the same "current
 * price". The backend is the only authority: the client's idea of the price is
 * never trusted.
 */
class BidService
{
    use DetectsConcurrencyErrors;

    public function __construct(
        private readonly IncrementCalculator $increments,
        private readonly AuctionEventRecorder $events,
    ) {}

    public function placeBid(Auction $auction, User $bidder, int $amount): BidResult
    {
        try {
            return $this->underAuctionLock($auction, function (Auction $locked) use ($bidder, $amount) {
                $this->assertOpenForBidding($locked, now());

                if ($locked->leader_id === $bidder->id) {
                    throw new ApiException(
                        ErrorCode::InvalidBid,
                        'You are already the highest bidder.',
                        ['reason' => 'ALREADY_LEADING'],
                    );
                }

                $minNextBid = $this->increments->minNextBid($locked);
                if ($amount < $minNextBid) {
                    throw new ApiException(
                        ErrorCode::BidTooLow,
                        "Your bid must be at least {$minNextBid}.",
                        ['min_next_bid' => $minNextBid, 'current_price' => $locked->current_price],
                    );
                }

                return $this->acceptBid($locked, $bidder, $amount, BidType::Manual, $minNextBid);
            });
        } catch (ApiException $e) {
            $this->recordRejection($auction, $bidder, $amount, $e);

            throw $e;
        }
    }

    /** Must run under the auction lock. */
    private function acceptBid(Auction $auction, User $bidder, int $amount, BidType $type, int $requiredMinimum): BidResult
    {
        $previousPrice = $auction->current_price;
        $previousLeaderId = $auction->leader_id;

        // The id is assigned while the lock is held, so id order is the true acceptance order.
        $bid = Bid::create([
            'auction_id' => $auction->id,
            'user_id' => $bidder->id,
            'amount' => $amount,
            'type' => $type,
        ]);

        $auction->forceFill(['current_price' => $amount, 'leader_id' => $bidder->id])->save();

        $this->events->record($auction, AuctionEventType::BidPlaced, $bidder, [
            'bid_id' => $bid->id,
            'bidder_id' => $bidder->id,
            'amount' => $amount,
            'type' => $type->value,
            'required_minimum' => $requiredMinimum,
            'previous_price' => $previousPrice,
            'new_price' => $amount,
        ]);

        if ($previousLeaderId !== $bidder->id) {
            $this->events->record($auction, AuctionEventType::LeaderChanged, $bidder, [
                'previous_leader_id' => $previousLeaderId,
                'new_leader_id' => $bidder->id,
                'price' => $amount,
            ]);
        }

        return new BidResult($bid, $auction, $this->increments->minNextBid($auction));
    }

    /**
     * Time is checked against the server clock, under the lock, and regardless of
     * status: once end_at has passed no bid is accepted, even if the closing job
     * has not run yet.
     */
    private function assertOpenForBidding(Auction $auction, DateTimeInterface $now): void
    {
        if (in_array($auction->status, [AuctionStatus::Draft, AuctionStatus::Cancelled], true)) {
            throw new ApiException(ErrorCode::AuctionNotLive, 'This auction is not open for bidding.', [
                'status' => $auction->status->value,
            ]);
        }

        if ($now < $auction->start_at) {
            throw new ApiException(ErrorCode::AuctionNotStarted, 'This auction has not started yet.', [
                'start_at' => ServerTime::format($auction->start_at),
                'server_time' => ServerTime::now(),
            ]);
        }

        if ($now >= $auction->end_at || $auction->status === AuctionStatus::Completed) {
            throw new ApiException(ErrorCode::AuctionEnded, 'This auction has already ended.', [
                'end_at' => ServerTime::format($auction->end_at),
                'server_time' => ServerTime::now(),
            ]);
        }

        if ($auction->status !== AuctionStatus::Live) {
            // Start time has passed but the start job has not opened it yet.
            throw new ApiException(ErrorCode::AuctionNotLive, 'This auction is about to open. Please try again in a moment.', [
                'status' => $auction->status->value,
            ]);
        }
    }

    /**
     * Runs $work inside a transaction holding the auction row lock. Lock wait
     * timeouts and deadlocks are retried a few times, then reported as 409.
     *
     * @template T
     *
     * @param  Closure(Auction): T  $work
     * @return T
     */
    private function underAuctionLock(Auction $auction, Closure $work): mixed
    {
        try {
            return DB::transaction(function () use ($auction, $work) {
                $timeout = max(1, (int) config('auction.bid_lock_wait_seconds'));
                DB::statement("SET SESSION innodb_lock_wait_timeout = {$timeout}");

                $locked = Auction::whereKey($auction->getKey())->lockForUpdate()->first()
                    ?? throw new ApiException(ErrorCode::AuctionNotFound, 'Auction not found.');

                return $work($locked);
            }, max(1, (int) config('auction.bid_transaction_attempts')));
        } catch (QueryException $e) {
            if ($this->causedByConcurrencyError($e)) {
                throw new ApiException(ErrorCode::BidConflictRetry, 'The auction is busy right now. Please try again.');
            }

            throw $e;
        }
    }

    /**
     * Rejected bids are logged after the bid transaction has rolled back, so the
     * audit trail keeps them even though nothing else changed. Logging is
     * best-effort: it must never turn a clean rejection into a server error.
     */
    private function recordRejection(Auction $auction, User $bidder, int $amount, ApiException $e): void
    {
        if ($e->errorCode === ErrorCode::AuctionNotFound) {
            return;
        }

        if ($e->errorCode === ErrorCode::BidConflictRetry) {
            // The bid was never evaluated, and the audit insert would wait on the
            // same busy row (foreign key check), so this goes to the app log instead.
            Log::warning('Bid not processed: auction row lock unavailable.', [
                'auction_id' => $auction->id,
                'bidder_id' => $bidder->id,
                'amount' => $amount,
            ]);

            return;
        }

        try {
            $this->events->record($auction, AuctionEventType::BidRejected, $bidder, [
                'bidder_id' => $bidder->id,
                'amount' => $amount,
                'reason' => $e->errorCode->value,
                'message' => $e->getMessage(),
                'details' => $e->details,
            ]);
        } catch (QueryException $logFailure) {
            Log::error('Could not record BID_REJECTED audit event.', [
                'auction_id' => $auction->id,
                'reason' => $e->errorCode->value,
                'error' => $logFailure->getMessage(),
            ]);
        }
    }
}
