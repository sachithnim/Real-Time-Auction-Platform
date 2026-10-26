<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Suspicious bidding patterns for admins to review. One row per pattern:
        // the dedupe key (rule, auction, bidders) is unique, so seeing the pattern
        // again updates the row instead of adding another.
        Schema::create('bid_flags', function (Blueprint $table) {
            $table->id();
            $table->string('dedupe_key', 100)->unique();
            $table->foreignId('auction_id')->constrained();
            $table->string('rule', 30);
            $table->foreignId('user_id')->constrained();
            $table->foreignId('related_user_id')->nullable()->constrained('users'); // second account (shared IP, alternating)
            $table->json('details');
            $table->string('status', 20)->default('OPEN'); // OPEN | REVIEWED
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('first_detected_at');
            $table->dateTime('last_detected_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bid_flags');
    }
};
