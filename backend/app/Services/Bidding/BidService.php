<?php

namespace App\Services\Bidding;

use App\Enums\AuctionEventType;
use App\Enums\AuctionStatus;
use App\Enums\BidType;
use App\Enums\ErrorCode;
use App\Exceptions\ApiException;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Models\User;
use App\Services\AuctionEventRecorder;
use App\Services\Realtime\AuctionBroadcaster;
use App\Support\ServerTime;
use Closure;
use DateTimeInterface;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        private readonly ProxyBidResolver $resolver,
        private readonly AuctionEventRecorder $events,
        private readonly AuctionBroadcaster $broadcaster,
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

                // A manual bid is literal: it competes with a maximum equal to its amount.
                $challenger = new ProxyCandidate($bidder->id, $amount, now(), BidType::Manual);

                return $this->resolveAndApply($locked, $challenger, $minNextBid, $bidder);
            });
        } catch (ApiException $e) {
            $this->recordRejection($auction, $bidder, $amount, $e);

            throw $e;
        }
    }

    /**
     * Sets or raises the bidder's private maximum. A leader raising their
     * maximum places no visible bid; anyone else immediately competes with it.
     */
    public function configureAutoBid(Auction $auction, User $bidder, int $maxAmount): BidResult
    {
        try {
            return $this->underAuctionLock($auction, function (Auction $locked) use ($bidder, $maxAmount) {
                $this->assertOpenForBidding($locked, now());

                $minNextBid = $this->increments->minNextBid($locked);
                $existing = AutoBid::where('auction_id', $locked->id)->where('user_id', $bidder->id)->first();
                $currentMax = $existing?->is_active ? $existing->max_amount : null;

                // A maximum can only go up, and must at least cover the next valid bid.
                $floor = max($minNextBid, $currentMax ?? 0);
                if ($maxAmount < $floor) {
                    throw new ApiException(
                        ErrorCode::MaxAutoBidReached,
                        "Your maximum must be at least {$floor}.",
                        ['minimum_max_amount' => $floor, 'min_next_bid' => $minNextBid],
                    );
                }

                $autoBid = $existing ?? new AutoBid(['auction_id' => $locked->id, 'user_id' => $bidder->id]);
                $changed = $existing === null || ! $existing->is_active || $existing->max_amount !== $maxAmount;
                $autoBid->forceFill(['max_amount' => $maxAmount, 'is_active' => true]);
                if ($changed) {
                    // Ties are won by whoever committed to the amount first.
                    $autoBid->max_set_at = now();
                }
                $autoBid->save();

                $this->events->record($locked, AuctionEventType::AutoBidConfigured, $bidder, [
                    'user_id' => $bidder->id,
                    'max_amount' => $maxAmount,
                    'previous_max_amount' => $currentMax,
                    'is_leader' => $locked->leader_id === $bidder->id,
                ]);

                if ($locked->leader_id === $bidder->id) {
                    return new BidResult($locked, $minNextBid, autoBid: $autoBid, actingUserId: $bidder->id);
                }

                $challenger = new ProxyCandidate($bidder->id, $maxAmount, $autoBid->max_set_at, BidType::Auto, $autoBid->id);

                return $this->resolveAndApply($locked, $challenger, $minNextBid, $bidder, $autoBid);
            });
        } catch (ApiException $e) {
            $this->recordRejection($auction, $bidder, $maxAmount, $e, 'AUTO_BID');

            throw $e;
        }
    }

    /**
     * Runs the proxy resolution for a challenger and commits its outcome.
     * Must run under the auction lock.
     */
    private function resolveAndApply(Auction $auction, ProxyCandidate $challenger, int $requiredMinimum, User $actor, ?AutoBid $autoBid = null): BidResult
    {
        $resolution = $this->resolver->resolve(
            $challenger,
            $this->defenders($auction, $challenger->userId),
            $auction->current_price,
            $auction->starting_price,
            fn (int $price) => $this->increments->incrementFor($price, $auction),
        );

        $previousPrice = $auction->current_price;
        $previousLeaderId = $auction->leader_id;
        $runningPrice = $previousPrice;
        $bids = [];

        foreach ($resolution->bids as $planned) {
            // Ids are assigned while the lock is held, so id order is the true acceptance order.
            $bid = Bid::create([
                'auction_id' => $auction->id,
                'user_id' => $planned->userId,
                'amount' => $planned->amount,
                'type' => $planned->type,
            ]);

            $this->events->record(
                $auction,
                $planned->type === BidType::Manual ? AuctionEventType::BidPlaced : AuctionEventType::AutoBidPlaced,
                // The acting bidder for their own bid; null (system) for automatic answers.
                $planned->userId === $actor->id ? $actor : null,
                [
                    'bid_id' => $bid->id,
                    'bidder_id' => $planned->userId,
                    'amount' => $planned->amount,
                    'type' => $planned->type->value,
                    'required_minimum' => $planned->userId === $challenger->userId ? $requiredMinimum : null,
                    'previous_price' => $runningPrice,
                    'new_price' => $planned->amount,
                ],
            );

            $runningPrice = $planned->amount;
            $bids[] = $bid;
        }

        $auction->forceFill(['current_price' => $resolution->price, 'leader_id' => $resolution->leaderId])->save();

        if ($previousLeaderId !== $resolution->leaderId) {
            $this->events->record($auction, AuctionEventType::LeaderChanged, $resolution->leaderId === $actor->id ? $actor : null, [
                'previous_leader_id' => $previousLeaderId,
                'new_leader_id' => $resolution->leaderId,
                'price' => $resolution->price,
            ]);
        }

        $extended = $this->extendIfSniping($auction, end($bids)->id);

        $minNextBid = $this->increments->minNextBid($auction);
        $exhausted = $this->deactivateExhaustedAutoBids($auction, $minNextBid);
        $outbid = $previousLeaderId !== null && $previousLeaderId !== $resolution->leaderId ? [$previousLeaderId] : [];

        // Assigns sequence numbers under the lock; events are sent after commit.
        $this->broadcaster->bidsAccepted(
            $auction,
            $bids,
            leaderChanged: $previousLeaderId !== $resolution->leaderId,
            extended: $extended,
            outbidUserIds: $outbid,
            exhaustedAutoBids: $exhausted,
        );

        return new BidResult(
            auction: $auction,
            minNextBid: $minNextBid,
            bids: $bids,
            autoBid: $autoBid?->refresh(),
            outbidUserIds: $outbid,
            exhaustedUserIds: $exhausted->pluck('user_id')->all(),
            actingUserId: $challenger->userId,
            extended: $extended,
        );
    }

    /**
     * Anti-sniping: a bid accepted inside the final window pushes the end back.
     * Must run under the auction lock, after the bid has been accepted.
     *
     * The extension is added to the current end (end_at += extension), not to
     * "now": it can never shorten an auction, and the latest possible end is
     * always original_end_at + max_extensions * extension_seconds.
     */
    private function extendIfSniping(Auction $auction, int $triggeringBidId): bool
    {
        if ($auction->anti_snipe_seconds === 0 || $auction->extensions_count >= $auction->max_extensions) {
            return false;
        }

        $windowStart = $auction->end_at->subSeconds($auction->anti_snipe_seconds);
        if (now()->lt($windowStart)) {
            return false;
        }

        $previousEnd = $auction->end_at;
        $auction->forceFill([
            'end_at' => $previousEnd->addSeconds($auction->extension_seconds),
            'extensions_count' => $auction->extensions_count + 1,
        ])->save();

        $this->events->record($auction, AuctionEventType::AuctionExtended, null, [
            'triggered_by_bid_id' => $triggeringBidId,
            'previous_end_at' => ServerTime::format($previousEnd),
            'new_end_at' => ServerTime::format($auction->end_at),
            'original_end_at' => ServerTime::format($auction->original_end_at),
            'extensions_count' => $auction->extensions_count,
            'max_extensions' => $auction->max_extensions,
        ]);

        return true;
    }

    /**
     * Everyone the challenger competes against: other bidders' active maximums,
     * plus the current leader's price when they lead with a manual bid.
     *
     * @return list<ProxyCandidate>
     */
    private function defenders(Auction $auction, int $challengerId): array
    {
        $defenders = AutoBid::where('auction_id', $auction->id)
            ->where('is_active', true)
            ->where('user_id', '!=', $challengerId)
            ->get()
            ->map(fn (AutoBid $auto) => new ProxyCandidate($auto->user_id, $auto->max_amount, $auto->max_set_at, BidType::Auto, $auto->id))
            ->all();

        $leaderId = $auction->leader_id;
        $leaderHasAutoBid = collect($defenders)->contains(fn (ProxyCandidate $c) => $c->userId === $leaderId);

        if ($leaderId !== null && $leaderId !== $challengerId && ! $leaderHasAutoBid) {
            $leadingBid = Bid::where('auction_id', $auction->id)->where('user_id', $leaderId)->orderByDesc('id')->first();
            $defenders[] = new ProxyCandidate($leaderId, $auction->current_price, $leadingBid->created_at, BidType::Manual);
        }

        return $defenders;
    }

    /**
     * Turns off every auto-bid that can no longer place a valid bid and is not
     * leading, and records it so the bidder can be told their maximum was reached.
     *
     * @return Collection<int, AutoBid> the auto-bids that were switched off
     */
    private function deactivateExhaustedAutoBids(Auction $auction, int $minNextBid): Collection
    {
        $exhausted = AutoBid::where('auction_id', $auction->id)
            ->where('is_active', true)
            ->where('user_id', '!=', $auction->leader_id)
            ->where('max_amount', '<', $minNextBid)
            ->get();

        foreach ($exhausted as $autoBid) {
            $autoBid->forceFill(['is_active' => false])->save();

            $this->events->record($auction, AuctionEventType::AutoBidMaxReached, null, [
                'user_id' => $autoBid->user_id,
                'max_amount' => $autoBid->max_amount,
                'price' => $auction->current_price,
                'min_next_bid' => $minNextBid,
            ]);
        }

        return $exhausted;
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
    private function recordRejection(Auction $auction, User $bidder, int $amount, ApiException $e, string $action = 'BID'): void
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
            $rejection = $this->events->record($auction, AuctionEventType::BidRejected, $bidder, [
                'action' => $action,
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

            return;
        }

        try {
            $this->broadcaster->bidRejected($rejection);
        } catch (Throwable $notifyFailure) {
            Log::warning('Could not notify the admin monitor of a rejected bid.', [
                'auction_id' => $auction->id,
                'error' => $notifyFailure->getMessage(),
            ]);
        }
    }
}
