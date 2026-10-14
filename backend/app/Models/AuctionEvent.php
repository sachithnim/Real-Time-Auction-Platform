<?php

namespace App\Models;

use App\Enums\AuctionEventType;
use App\Models\Concerns\IsInsertOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One audit trail entry. Insert-only: the trail is never rewritten.
 */
#[Fillable(['auction_id', 'type', 'actor_id', 'payload'])]
class AuctionEvent extends Model
{
    use IsInsertOnly;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'type' => AuctionEventType::class,
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
