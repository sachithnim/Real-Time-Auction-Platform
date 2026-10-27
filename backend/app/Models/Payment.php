<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The winner's (simulated) payment for a sold auction. Changed only by
 * PaymentService, under a row lock.
 */
class Payment extends Model
{
    protected $attributes = [
        'status' => 'PENDING',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'attempts' => 'integer',
            'paid_at' => 'immutable_datetime',
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
