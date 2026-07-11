<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo/video galleries for Seva and Hall, mirroring temple_event_media.
 * Photos live on R2 (image_path); videos are links (video_url).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_seva_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seva_id')->constrained('temple_sevas')->cascadeOnDelete();
            $table->enum('media_type', ['photo', 'video'])->default('photo');
            $table->string('image_path', 500)->nullable();
            $table->string('video_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['seva_id', 'sort_order']);
        });

        Schema::create('temple_hall_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hall_id')->constrained('temple_halls')->cascadeOnDelete();
            $table->enum('media_type', ['photo', 'video'])->default('photo');
            $table->string('image_path', 500)->nullable();
            $table->string('video_url', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['hall_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_seva_media');
        Schema::dropIfExists('temple_hall_media');
    }
};
