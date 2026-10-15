<?php

namespace App\Services;

use App\Enums\AuctionEventType;
use App\Models\Auction;
use App\Models\AuctionEvent;
use App\Models\User;

/**
 * Writes the audit trail. Call it inside the same transaction as the change
 * being recorded, so the event and the change commit or roll back together.
 */
class AuctionEventRecorder
{
    /**
     * @param  array<string, mixed>  $payload  Enough data to reconstruct the change (old/new values, reasons).
     */
    public function record(Auction $auction, AuctionEventType $type, ?User $actor, array $payload = []): AuctionEvent
    {
        return AuctionEvent::create([
            'auction_id' => $auction->id,
            'type' => $type,
            'actor_id' => $actor?->id,
            'payload' => $payload,
        ]);
    }
}
