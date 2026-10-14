<?php

namespace App\Models;

use App\Enums\AuctionResult;
use App\Enums\AuctionStatus;
use Database\Factories\AuctionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Only the admin-editable fields are fillable. Status, price, leader,
 * winner and extension counters are changed by services under a row lock.
 */
#[Fillable([
    'title', 'description', 'starting_price', 'reserve_price', 'min_increment',
    'start_at', 'end_at', 'anti_snipe_seconds', 'extension_seconds', 'max_extensions',
])]
// The reserve is private; it must never leak through a default serialization.
#[Hidden(['reserve_price'])]
class Auction extends Model
{
    /** @use HasFactory<AuctionFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'DRAFT',
        'extensions_count' => 0,
        'event_sequence' => 0,
    ];

    protected function casts(): array
    {
        return [
            'starting_price' => 'integer',
            'reserve_price' => 'integer',
            'min_increment' => 'integer',
            'current_price' => 'integer',
            'final_price' => 'integer',
            'anti_snipe_seconds' => 'integer',
            'extension_seconds' => 'integer',
            'max_extensions' => 'integer',
            'extensions_count' => 'integer',
            'event_sequence' => 'integer',
            'start_at' => 'immutable_datetime',
            'end_at' => 'immutable_datetime',
            'original_end_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'status' => AuctionStatus::class,
            'result' => AuctionResult::class,
        ];
    }

    public function hasBids(): bool
    {
        return $this->leader_id !== null;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function winner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'winner_id');
    }

    /** Accepted bids in the order they were accepted. */
    public function bids(): HasMany
    {
        return $this->hasMany(Bid::class)->orderBy('id');
    }

    public function autoBids(): HasMany
    {
        return $this->hasMany(AutoBid::class);
    }

    /** Audit trail in the order it was written. */
    public function events(): HasMany
    {
        return $this->hasMany(AuctionEvent::class)->orderBy('id');
    }
}
