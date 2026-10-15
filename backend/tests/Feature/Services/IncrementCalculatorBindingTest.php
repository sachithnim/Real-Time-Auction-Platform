<?php

namespace Tests\Feature\Services;

use App\Models\BidIncrementRule;
use App\Services\Bidding\IncrementCalculator;
use Database\Seeders\BidIncrementRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncrementCalculatorBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculator_uses_the_configurable_tier_table(): void
    {
        $this->seed(BidIncrementRuleSeeder::class);
        BidIncrementRule::where('from_amount', 10_001)->update(['increment' => 750]);

        $calculator = app(IncrementCalculator::class);

        $this->assertSame(750, $calculator->tierIncrement(20_000));
        $this->assertSame(100, $calculator->tierIncrement(10_000));
    }
}
