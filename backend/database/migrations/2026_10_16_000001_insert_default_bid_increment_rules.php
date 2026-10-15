<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reference data the bid engine cannot work without, so every environment
     * (production, Docker, tests) has it. Admins may change the rows later.
     */
    public function up(): void
    {
        $now = now();

        DB::table('bid_increment_rules')->insertOrIgnore([
            ['from_amount' => 0, 'to_amount' => 10_000, 'increment' => 100, 'created_at' => $now, 'updated_at' => $now],
            ['from_amount' => 10_001, 'to_amount' => 50_000, 'increment' => 500, 'created_at' => $now, 'updated_at' => $now],
            ['from_amount' => 50_001, 'to_amount' => 100_000, 'increment' => 1_000, 'created_at' => $now, 'updated_at' => $now],
            ['from_amount' => 100_001, 'to_amount' => null, 'increment' => 2_500, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('bid_increment_rules')->whereIn('from_amount', [0, 10_001, 50_001, 100_001])->delete();
    }
};
