<?php

namespace App\Http\Resources;

use App\Models\Bid;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bid as shown to the bidder who placed it.
 *
 * @mixin Bid
 */
class BidResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'type' => $this->type->value,
            'created_at' => ServerTime::format($this->created_at),
        ];
    }
}
