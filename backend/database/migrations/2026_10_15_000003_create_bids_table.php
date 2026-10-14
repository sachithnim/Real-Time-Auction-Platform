<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Insert-only history of accepted bids. Ordered by id, which is
        // assigned while the auction row is locked, not by timestamp.
        Schema::create('bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('amount');
            $table->string('type', 10); // MANUAL | AUTO
            $table->dateTime('created_at', 6);

            $table->index(['auction_id', 'user_id']);
        });

        DB::statement('ALTER TABLE bids ADD CONSTRAINT bids_positive_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bids');
    }
};
