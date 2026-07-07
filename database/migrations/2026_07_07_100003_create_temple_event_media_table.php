<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_event_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('temple_events')->cascadeOnDelete();
            $table->enum('media_type', ['photo', 'video'])->default('photo');
            $table->string('image_path', 500)->nullable();  // R2 key for photos
            $table->string('video_url', 500)->nullable();    // YouTube / hosted link for videos
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_event_media');
    }
};
