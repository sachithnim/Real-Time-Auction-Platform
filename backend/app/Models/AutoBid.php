<?php

namespace App\Models;

use Database\Factories\AutoBidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bidder's private proxy maximum for one auction.
 */
#[Fillable(['auction_id', 'user_id', 'max_amount', 'is_active', 'max_set_at'])]
// The maximum is private to its owner and must never leak through a default serialization.
#[Hidden(['max_amount'])]
class AutoBid extends Model
{
    /** @use HasFactory<AutoBidFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'max_amount' => 'integer',
            'is_active' => 'boolean',
            'max_set_at' => 'immutable_datetime',
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
