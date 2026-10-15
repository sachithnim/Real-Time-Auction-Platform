<?php

namespace App\Http\Resources\Admin;

use App\Models\AuctionEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One audit trail entry, with full detail. Admin endpoints only.
 *
 * @mixin AuctionEvent
 */
class AuctionEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            // Null actor = a system action (scheduler, jobs, automatic proxy answers).
            'actor' => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
                'role' => $this->actor->role->value,
            ],
            'payload' => (object) $this->payload,
            // Microseconds keep events written in the same transaction distinguishable.
            'created_at' => $this->created_at->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
