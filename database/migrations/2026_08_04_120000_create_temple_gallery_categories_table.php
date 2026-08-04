<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_gallery_categories', function (Blueprint $table) {
            $table->id();
            // Stored on temple_gallery_images.category as a loose slug
            // reference (existing rows predate this table).
            $table->string('slug')->unique();
            $table->string('name_gu');
            $table->string('name_hi')->nullable();
            $table->string('name_en')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_gallery_categories');
    }
};
