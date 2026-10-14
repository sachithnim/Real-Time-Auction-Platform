<?php

namespace Database\Seeders;

use App\Models\BidIncrementRule;
use Illuminate\Database\Seeder;

/**
 * The platform-wide increment tiers from the assignment.
 */
class BidIncrementRuleSeeder extends Seeder
{
    /** @var list<array{from_amount: int, to_amount: int|null, increment: int}> */
    public const TIERS = [
        ['from_amount' => 0, 'to_amount' => 10_000, 'increment' => 100],
        ['from_amount' => 10_001, 'to_amount' => 50_000, 'increment' => 500],
        ['from_amount' => 50_001, 'to_amount' => 100_000, 'increment' => 1_000],
        ['from_amount' => 100_001, 'to_amount' => null, 'increment' => 2_500],
    ];

    public function run(): void
    {
        foreach (self::TIERS as $tier) {
            BidIncrementRule::updateOrCreate(['from_amount' => $tier['from_amount']], $tier);
        }
    }
}
