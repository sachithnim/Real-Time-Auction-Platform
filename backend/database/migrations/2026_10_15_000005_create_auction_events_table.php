<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Insert-only audit trail, written in the same transaction as the change it records.
        Schema::create('auction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained();
            $table->string('type', 40);
            $table->foreignId('actor_id')->nullable()->constrained('users'); // null = system (scheduler/jobs)
            $table->json('payload');
            $table->dateTime('created_at', 6);

            $table->index(['auction_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_events');
    }
};
