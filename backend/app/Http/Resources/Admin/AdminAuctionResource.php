<?php

namespace App\Http\Resources\Admin;

use App\Http\Resources\PaymentResource;
use App\Models\Auction;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full auction view for admins, including the private reserve price.
 * Never returned from a bidder-facing endpoint.
 *
 * @mixin Auction
 */
class AdminAuctionResource extends JsonResource
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
            'category_id' => $this->category_id,
            'category' => $this->category === null ? null : [
                'id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug,
            ],
            'status' => $this->status->value,
            'result' => $this->result?->value,
            'starting_price' => $this->starting_price,
            'reserve_price' => $this->reserve_price,
            'min_increment' => $this->min_increment,
            'current_price' => $this->current_price,
            'final_price' => $this->final_price,
            'start_at' => ServerTime::format($this->start_at),
            'end_at' => ServerTime::format($this->end_at),
            'original_end_at' => ServerTime::format($this->original_end_at),
            'anti_snipe_seconds' => $this->anti_snipe_seconds,
            'extension_seconds' => $this->extension_seconds,
            'max_extensions' => $this->max_extensions,
            'extensions_count' => $this->extensions_count,
            'leader_id' => $this->leader_id,
            'winner_id' => $this->winner_id,
            'created_by' => $this->created_by,
            'bids_count' => $this->whenCounted('bids'),
            'payment' => $this->whenLoaded('payment', fn () => $this->payment === null ? null : new PaymentResource($this->payment)),
            'started_at' => ServerTime::format($this->started_at),
            'ended_at' => ServerTime::format($this->ended_at),
            'cancelled_at' => ServerTime::format($this->cancelled_at),
            'created_at' => ServerTime::format($this->created_at),
            'updated_at' => ServerTime::format($this->updated_at),
        ];
    }
}
