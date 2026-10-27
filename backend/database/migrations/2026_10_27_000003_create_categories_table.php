<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Auction categories, managed by admins. The slug is used in URLs and filters.
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->string('slug', 70)->unique();
            $table->timestamps();
        });

        // Optional category per auction. The foreign key refuses deleting a category
        // that is still in use (the API checks first and answers 409).
        Schema::table('auctions', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('description')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('auctions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
        });
        Schema::dropIfExists('categories');
    }
};
