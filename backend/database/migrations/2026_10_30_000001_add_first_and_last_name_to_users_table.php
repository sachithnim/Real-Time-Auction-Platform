<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Registration now asks for first and last name separately. "name" stays as the
        // full display name that admin views and statistics already read.
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name', 50)->nullable()->after('id');
            $table->string('last_name', 50)->nullable()->after('first_name');
        });

        // Existing accounts: split the full name at the first space.
        DB::table('users')->orderBy('id')->each(function (object $user) {
            [$first, $last] = array_pad(explode(' ', trim($user->name), 2), 2, null);
            DB::table('users')->where('id', $user->id)->update(['first_name' => $first, 'last_name' => $last]);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
