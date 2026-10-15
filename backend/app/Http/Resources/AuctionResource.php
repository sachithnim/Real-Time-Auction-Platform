<?php

namespace App\Http\Resources;

use App\Models\Auction;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bidder-facing auction. Privacy rules live here: never the reserve price
 * (only whether it is met), never user ids or emails.
 *
 * @mixin Auction
 */
class AuctionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'result' => $this->result?->value,
            'starting_price' => $this->starting_price,
            'current_price' => $this->current_price,
            'final_price' => $this->final_price,
            'min_increment' => $this->min_increment,
            'reserve_met' => $this->reserveMet(),
            'bids_count' => $this->whenCounted('bids'),
            'start_at' => ServerTime::format($this->start_at),
            'end_at' => ServerTime::format($this->end_at),
            'original_end_at' => ServerTime::format($this->original_end_at),
            'anti_snipe_seconds' => $this->anti_snipe_seconds,
            'extension_seconds' => $this->extension_seconds,
            'max_extensions' => $this->max_extensions,
            'extensions_count' => $this->extensions_count,
            'started_at' => ServerTime::format($this->started_at),
            'ended_at' => ServerTime::format($this->ended_at),
            'cancelled_at' => ServerTime::format($this->cancelled_at),
        ];
    }

    /** True when there is no reserve or the current price has reached it. */
    private function reserveMet(): bool
    {
        if ($this->reserve_price === null) {
            return true;
        }

        return $this->current_price !== null && $this->current_price >= $this->reserve_price;
    }
}
