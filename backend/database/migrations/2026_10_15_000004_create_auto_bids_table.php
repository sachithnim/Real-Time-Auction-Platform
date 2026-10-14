<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One private proxy maximum per bidder per auction.
        Schema::create('auto_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('max_amount'); // private: never broadcast or shown to others
            $table->boolean('is_active')->default(true);
            // When max_amount last changed; equal maximums are won by whoever reached it first.
            $table->dateTime('max_set_at', 6);
            $table->timestamps();

            $table->unique(['auction_id', 'user_id']);
            $table->index(['auction_id', 'is_active', 'max_amount']);
        });

        DB::statement('ALTER TABLE auto_bids ADD CONSTRAINT auto_bids_positive_max CHECK (max_amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_bids');
    }
};
