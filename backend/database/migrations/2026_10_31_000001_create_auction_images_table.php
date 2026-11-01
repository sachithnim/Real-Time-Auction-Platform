<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Item photos. The file lives on the private "local" disk and is served by the
        // API under its random filename, so no public storage link is needed.
        Schema::create('auction_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('filename', 60)->unique(); // 40 random characters + extension: the public name
            $table->string('path');
            $table->string('mime_type', 30);
            $table->unsignedInteger('size'); // bytes
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedTinyInteger('position'); // 0 is the cover
            $table->timestamps();

            $table->index(['auction_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_images');
    }
};
