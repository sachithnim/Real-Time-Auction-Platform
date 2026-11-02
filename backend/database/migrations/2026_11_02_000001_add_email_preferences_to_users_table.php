<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which notification emails the user has switched off, e.g. {"OUTBID": false}.
        // Null or a missing key means "on": every email starts enabled.
        Schema::table('users', function (Blueprint $table) {
            $table->json('email_preferences')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_preferences');
        });
    }
};
