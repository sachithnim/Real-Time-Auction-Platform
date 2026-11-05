<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class AdminBidderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'joined_at' => ServerTime::format($this->created_at),
            'disabled_at' => ServerTime::format($this->disabled_at),
            'bids_count' => $this->whenCounted('bids'),
            'wins_count' => $this->whenCounted('wonAuctions'),
        ];
    }
}
