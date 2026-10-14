<?php

namespace App\Models;

use App\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'key', 'request_hash', 'status', 'response_code', 'response_body'])]
class IdempotencyKey extends Model
{
    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_code' => 'integer',
            'response_body' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
