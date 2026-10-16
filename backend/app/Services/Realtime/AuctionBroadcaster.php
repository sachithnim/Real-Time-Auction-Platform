<?php

namespace App\Services\Realtime;

use App\Enums\AuctionResult;
use App\Events\Auction\AuctionCancelled;
use App\Events\Auction\AuctionChannelEvent;
use App\Events\Auction\AuctionEnded;
use App\Events\Auction\AuctionExtended;
use App\Events\Auction\AuctionStarted;
use App\Events\Auction\AuctionUpdated;
use App\Events\Auction\BidPlaced;
use App\Events\Auction\LeaderChanged;
use App\Events\Auction\PriceChanged;
use App\Events\User\AuctionWon;
use App\Events\User\AutoBidMaxReached;
use App\Events\User\Outbid;
use App\Models\Auction;
use App\Models\AutoBid;
use App\Models\Bid;
use App\Services\Bidding\BidderPseudonyms;
use App\Services\Bidding\IncrementCalculator;
use App\Support\ServerTime;

/**
 * Builds and dispatches every real-time event. All privacy rules for live
 * updates live here: bidders appear only as per-auction pseudonyms, and no
 * user id, email, reserve or auto-bid maximum reaches a public channel.
 *
 * Must be called inside the transaction that holds the auction row lock: the
 * per-auction sequence numbers are assigned there, so they follow the true
 * order of changes. The events themselves are sent only after commit.
 */
class AuctionBroadcaster
{
    public function __construct(
        private readonly BidderPseudonyms $names,
        private readonly IncrementCalculator $increments,
    ) {}

    /**
     * @param  list<Bid>  $bids  visible bids just accepted, in order
     * @param  list<int>  $outbidUserIds
     * @param  iterable<AutoBid>  $exhaustedAutoBids
     */
    public function bidsAccepted(
        Auction $auction,
        array $bids,
        bool $leaderChanged,
        bool $extended,
        array $outbidUserIds,
        iterable $exhaustedAutoBids,
    ): void {
        $state = $this->priceState($auction);

        foreach ($bids as $bid) {
            $this->publish($auction, BidPlaced::class, [
                'bid' => [
                    'id' => $bid->id,
                    'amount' => $bid->amount,
                    'type' => $bid->type->value,
                    'bidder' => $this->names->nameFor($auction, $bid->user_id, null),
                    'created_at' => ServerTime::format($bid->created_at),
                ],
                ...$state,
            ]);
        }

        $this->publish($auction, PriceChanged::class, $state);

        if ($leaderChanged) {
            $this->publish($auction, LeaderChanged::class, [
                'leader' => $this->names->nameFor($auction, $auction->leader_id, null),
                'current_price' => $auction->current_price,
            ]);
        }

        if ($extended) {
            $this->publish($auction, AuctionExtended::class, [
                'end_at' => ServerTime::format($auction->end_at),
                'original_end_at' => ServerTime::format($auction->original_end_at),
                'extensions_count' => $auction->extensions_count,
                'max_extensions' => $auction->max_extensions,
            ]);
        }

        foreach ($outbidUserIds as $userId) {
            Outbid::dispatch($userId, ServerTime::now(), [
                'auction_id' => $auction->id,
                'title' => $auction->title,
                ...$state,
            ]);
        }

        foreach ($exhaustedAutoBids as $autoBid) {
            // The maximum goes only to its owner, on their private channel.
            AutoBidMaxReached::dispatch($autoBid->user_id, ServerTime::now(), [
                'auction_id' => $auction->id,
                'title' => $auction->title,
                'max_amount' => $autoBid->max_amount,
                ...$state,
            ]);
        }

        $this->listing($auction);
    }

    public function started(Auction $auction): void
    {
        $this->publish($auction, AuctionStarted::class, [
            'status' => $auction->status->value,
            'start_at' => ServerTime::format($auction->start_at),
            'end_at' => ServerTime::format($auction->end_at),
            'min_next_bid' => $this->increments->minNextBid($auction),
        ]);
        $this->listing($auction);
    }

    public function ended(Auction $auction): void
    {
        $sold = $auction->result === AuctionResult::Sold;

        $this->publish($auction, AuctionEnded::class, [
            'status' => $auction->status->value,
            'result' => $auction->result->value,
            'final_price' => $auction->final_price,
            'winner' => $sold ? $this->names->nameFor($auction, $auction->winner_id, null) : null,
            'ended_at' => ServerTime::format($auction->ended_at),
        ]);

        if ($sold) {
            AuctionWon::dispatch($auction->winner_id, ServerTime::now(), [
                'auction_id' => $auction->id,
                'title' => $auction->title,
                'final_price' => $auction->final_price,
            ]);
        }

        $this->listing($auction);
    }

    public function cancelled(Auction $auction): void
    {
        $this->publish($auction, AuctionCancelled::class, [
            'status' => $auction->status->value,
            'cancelled_at' => ServerTime::format($auction->cancelled_at),
        ]);
        $this->listing($auction);
    }

    /**
     * @param  class-string<AuctionChannelEvent>  $event
     * @param  array<string, mixed>  $data
     */
    private function publish(Auction $auction, string $event, array $data): void
    {
        // Assigned under the row lock and persisted in the same transaction.
        $auction->event_sequence++;
        $auction->save();

        $event::dispatch($auction->id, $auction->event_sequence, ServerTime::now(), $data);
    }

    private function listing(Auction $auction): void
    {
        AuctionUpdated::dispatch([
            'id' => $auction->id,
            'status' => $auction->status->value,
            'result' => $auction->result?->value,
            'current_price' => $auction->current_price,
            'end_at' => ServerTime::format($auction->end_at),
            'bids_count' => $auction->bids()->count(),
            'sequence' => $auction->event_sequence,
            'server_time' => ServerTime::now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function priceState(Auction $auction): array
    {
        return [
            'current_price' => $auction->current_price,
            'min_next_bid' => $this->increments->minNextBid($auction),
            'reserve_met' => $auction->reserve_price === null || $auction->current_price >= $auction->reserve_price,
            'bids_count' => $auction->bids()->count(),
        ];
    }
}
