<?php

namespace App\Http\Resources\Admin;

use App\Models\Bid;
use App\Services\Bidding\BidderPseudonyms;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bid with the bidder's real identity. Admin endpoints only.
 *
 * @mixin Bid
 */
class AdminBidResource extends JsonResource
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
            'bidder' => [
                'id' => $this->user_id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'pseudonym' => app(BidderPseudonyms::class)->nameFor($this->auction, $this->user_id, null),
            ],
            'created_at' => ServerTime::format($this->created_at),
        ];
    }
}
