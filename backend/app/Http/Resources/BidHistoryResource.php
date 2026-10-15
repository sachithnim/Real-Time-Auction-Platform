<?php

namespace App\Http\Resources;

use App\Models\Bid;
use App\Services\Bidding\BidderPseudonyms;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One line of the public bid history. Privacy rules live here: the bidder is
 * a per-auction pseudonym ("Bidder 3") or "You"; never a user id, email or
 * auto-bid maximum.
 *
 * @mixin Bid
 */
class BidHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'type' => $this->type->value,
            'bidder' => app(BidderPseudonyms::class)->nameFor($this->auction, $this->user_id, $viewer),
            'is_you' => $viewer !== null && $viewer->id === $this->user_id,
            'created_at' => ServerTime::format($this->created_at),
        ];
    }
}
