<?php

namespace App\Models;

use App\Enums\FlagRule;
use App\Enums\FlagStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A suspicious bidding pattern for an admin to review. Created and updated by
 * SuspiciousBidDetector; admins only change its review status.
 */
class BidFlag extends Model
{
    protected function casts(): array
    {
        return [
            'rule' => FlagRule::class,
            'status' => FlagStatus::class,
            'details' => 'array',
            'occurrences' => 'integer',
            'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
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

    public function relatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'related_user_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
