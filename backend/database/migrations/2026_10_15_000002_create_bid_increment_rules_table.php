<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Price tiers: a price in [from_amount, to_amount] uses this increment.
        // to_amount is null for the open-ended top tier.
        Schema::create('bid_increment_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_amount')->unique();
            $table->unsignedBigInteger('to_amount')->nullable();
            $table->unsignedBigInteger('increment');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE bid_increment_rules
            ADD CONSTRAINT bid_increment_rules_positive_increment CHECK (increment > 0),
            ADD CONSTRAINT bid_increment_rules_valid_range CHECK (to_amount IS NULL OR to_amount >= from_amount)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_increment_rules');
    }
};
