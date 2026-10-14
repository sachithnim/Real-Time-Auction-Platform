<?php

namespace App\Models;

use App\Enums\BidType;
use App\Models\Concerns\IsInsertOnly;
use Database\Factories\BidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An accepted bid. Insert-only: history is never rewritten.
 */
#[Fillable(['auction_id', 'user_id', 'amount', 'type'])]
class Bid extends Model
{
    /** @use HasFactory<BidFactory> */
    use HasFactory, IsInsertOnly;

    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'type' => BidType::class,
            'created_at' => 'immutable_datetime',
        ];
    }

    public function auction(): BelongsTo
    {
        return $this->belongsTo(Auction::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
