<?php

namespace App\Http\Resources;

use App\Models\AutoBid;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bidder's own auto-bid. The maximum is private: this resource is only ever
 * returned to its owner.
 *
 * @mixin AutoBid
 */
class AutoBidResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'max_amount' => $this->max_amount,
            'is_active' => $this->is_active,
            'max_set_at' => ServerTime::format($this->max_set_at),
        ];
    }
}
