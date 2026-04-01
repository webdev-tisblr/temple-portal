<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_gallery_images', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('image_path', 500);
            $table->string('thumbnail_path', 500)->nullable();
            $table->string('medium_path', 500)->nullable();
            $table->enum('category', ['temple', 'deity', 'festival', 'event', 'wallpaper', 'other'])->default('temple');
            $table->boolean('is_wallpaper')->default(false);
            $table->integer('sort_order')->default(0);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index('category');
            $table->index('is_wallpaper');
            $table->index('sort_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_gallery_images');
    }
};
