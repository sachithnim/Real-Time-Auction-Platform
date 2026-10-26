<?php

namespace App\Http\Resources\Admin;

use App\Models\BidFlag;
use App\Models\User;
use App\Support\ServerTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One suspicious bidding flag, with the accounts involved. Admin endpoints only:
 * it contains identities and, for shared-IP flags, IP addresses.
 *
 * @mixin BidFlag
 */
class BidFlagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'rule' => $this->rule->value,
            'status' => $this->status->value,
            'auction' => ['id' => $this->auction_id, 'title' => $this->auction?->title],
            'bidders' => collect([$this->user, $this->relatedUser])
                ->filter()
                ->map(fn (User $user) => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email])
                ->values()
                ->all(),
            'details' => (object) $this->details,
            'occurrences' => $this->occurrences,
            'first_detected_at' => ServerTime::format($this->first_detected_at),
            'last_detected_at' => ServerTime::format($this->last_detected_at),
            'reviewed_by' => $this->reviewer === null ? null : ['id' => $this->reviewer->id, 'name' => $this->reviewer->name],
            'reviewed_at' => ServerTime::format($this->reviewed_at),
        ];
    }
}
