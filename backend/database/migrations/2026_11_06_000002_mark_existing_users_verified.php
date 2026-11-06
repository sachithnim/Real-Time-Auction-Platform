<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Email verification starts with this release. Accounts that already exist
     * signed up before it, so they count as verified rather than being locked out
     * of bidding overnight.
     */
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // Nothing to undo: it is not known which accounts were unverified before.
    }
};
