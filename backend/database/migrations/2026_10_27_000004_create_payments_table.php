<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One simulated payment per sold auction, owed by its winner. Only the card
        // brand and last four digits are kept: never the full number or the CVC.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->unique()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->unsignedBigInteger('amount'); // whole rupees: the auction's final price
            $table->string('status', 20)->default('PENDING'); // PENDING | PAID | FAILED
            $table->string('card_brand', 20)->nullable();
            $table->char('card_last4', 4)->nullable();
            $table->string('reference', 20)->nullable()->unique(); // receipt number once paid
            $table->string('failure_reason', 40)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
