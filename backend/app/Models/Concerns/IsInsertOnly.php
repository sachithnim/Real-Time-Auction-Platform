<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * History records (accepted bids, audit events) are written once and never
 * changed. Any attempt to update or delete one through the model fails loudly.
 */
trait IsInsertOnly
{
    public static function bootIsInsertOnly(): void
    {
        static::updating(function ($model) {
            throw new LogicException(class_basename($model).' records are immutable and cannot be updated.');
        });

        static::deleting(function ($model) {
            throw new LogicException(class_basename($model).' records are immutable and cannot be deleted.');
        });
    }
}
