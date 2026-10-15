<?php

namespace App\Models;

use App\Enums\IdempotencyStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'key', 'request_hash', 'status', 'response_code', 'response_body'])]
class IdempotencyKey extends Model
{
    use Prunable;

    /** Retries happen within seconds or minutes; a day of history is plenty. */
    public const RETENTION_HOURS = 24;

    protected function casts(): array
    {
        return [
            'status' => IdempotencyStatus::class,
            'response_code' => 'integer',
            'response_body' => 'array',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subHours(self::RETENTION_HOURS));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
