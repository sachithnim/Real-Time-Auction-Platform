<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auctions', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('description');

            // Money: whole rupees as unsigned integers, never floats.
            $table->unsignedBigInteger('starting_price');
            $table->unsignedBigInteger('reserve_price')->nullable(); // private, never shown to bidders
            $table->unsignedBigInteger('min_increment');
            $table->unsignedBigInteger('current_price')->nullable(); // null until the first bid

            // Times are UTC. end_at moves forward on anti-sniping extensions;
            // original_end_at keeps the end time the auction was scheduled with.
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->dateTime('original_end_at');

            $table->unsignedInteger('anti_snipe_seconds');
            $table->unsignedInteger('extension_seconds');
            $table->unsignedSmallInteger('max_extensions');
            $table->unsignedSmallInteger('extensions_count')->default(0);

            $table->string('status', 20)->default('DRAFT');
            $table->string('result', 20)->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('leader_id')->nullable()->constrained('users');
            $table->foreignId('winner_id')->nullable()->constrained('users');
            $table->unsignedBigInteger('final_price')->nullable();

            // Per-auction counter for real-time event ordering, incremented under the row lock.
            $table->unsignedBigInteger('event_sequence')->default(0);

            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            // Sweeps: "SCHEDULED and start_at <= now", "LIVE and end_at <= now".
            $table->index(['status', 'start_at']);
            $table->index(['status', 'end_at']);
        });

        // The database refuses impossible auctions even if application code has a bug.
        DB::statement('ALTER TABLE auctions
            ADD CONSTRAINT auctions_end_after_start CHECK (end_at > start_at),
            ADD CONSTRAINT auctions_reserve_not_below_start CHECK (reserve_price IS NULL OR reserve_price >= starting_price),
            ADD CONSTRAINT auctions_positive_increment CHECK (min_increment > 0),
            ADD CONSTRAINT auctions_extensions_within_max CHECK (extensions_count <= max_extensions),
            ADD CONSTRAINT auctions_price_not_below_start CHECK (current_price IS NULL OR current_price >= starting_price)');
    }

    public function down(): void
    {
        Schema::dropIfExists('auctions');
    }
};
