<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Remembers bid/auto-bid requests by their Idempotency-Key header so a
        // retried request is answered with the original response, not re-executed.
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('key', 100);
            $table->char('request_hash', 64); // sha256 of method + path + body
            $table->string('status', 20); // PROCESSING | COMPLETED
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index('created_at'); // pruning old keys
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
