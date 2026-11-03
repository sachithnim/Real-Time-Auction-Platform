<?php

namespace App\Http\Resources;

use App\Enums\AuctionStatus;
use App\Models\Auction;
use Illuminate\Http\Request;

/**
 * An auction on the bidder's "My bids" page: the public auction, plus how the
 * viewer stands in it. Everything under `my_bid` is the viewer's own data
 * (their bids, their private maximum, their payment), never anyone else's.
 *
 * @mixin Auction
 */
class MyBidResource extends AuctionResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewerId = $request->user()->id;
        $autoBid = $this->autoBids->firstWhere('user_id', $viewerId);
        $won = $this->status === AuctionStatus::Completed && $this->winner_id === $viewerId;

        return [
            ...parent::toArray($request),
            'my_bid' => [
                'state' => $this->state($viewerId, $won),
                'highest_bid' => (int) $this->my_highest_bid,
                'bids_count' => (int) $this->my_bids_count,
                'max_amount' => $autoBid?->max_amount,
                'max_active' => (bool) $autoBid?->is_active,
                // Only the winner has a payment to make.
                'payment_status' => $won ? $this->payment?->status->value : null,
            ],
        ];
    }

    /** LEADING or OUTBID while live; WON, LOST or CANCELLED once closed. */
    private function state(int $viewerId, bool $won): string
    {
        return match ($this->status) {
            AuctionStatus::Live => $this->leader_id === $viewerId ? 'LEADING' : 'OUTBID',
            AuctionStatus::Cancelled => 'CANCELLED',
            default => $won ? 'WON' : 'LOST',
        };
    }
}
