<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Auctions a bidder follows. Private to that bidder; one row per pair.
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('auction_id')->constrained();
            $table->timestamps();

            $table->unique(['user_id', 'auction_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchlists');
    }
};
