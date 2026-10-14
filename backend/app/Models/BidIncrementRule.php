<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A price tier: prices in [from_amount, to_amount] use this increment.
 * to_amount is null for the open-ended top tier.
 */
#[Fillable(['from_amount', 'to_amount', 'increment'])]
class BidIncrementRule extends Model
{
    protected function casts(): array
    {
        return [
            'from_amount' => 'integer',
            'to_amount' => 'integer',
            'increment' => 'integer',
        ];
    }
}
