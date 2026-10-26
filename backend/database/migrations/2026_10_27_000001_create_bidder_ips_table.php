<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The IP addresses each bidder used on each auction, for the shared-IP
        // check. Admin-only data: never returned to bidders.
        Schema::create('bidder_ips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->string('ip_address', 45); // fits IPv6
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');

            $table->unique(['auction_id', 'user_id', 'ip_address']);
            $table->index(['auction_id', 'ip_address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bidder_ips');
    }
};
