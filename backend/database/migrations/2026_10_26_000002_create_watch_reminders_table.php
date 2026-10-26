<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reminders already sent. The unique key makes "once per bidder, auction
        // and kind" a database guarantee, even if two sweeps run at the same time.
        Schema::create('watch_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('auction_id')->constrained();
            $table->string('kind', 20); // STARTING_SOON | ENDING_SOON
            $table->dateTime('sent_at');

            $table->unique(['user_id', 'auction_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_reminders');
    }
};
