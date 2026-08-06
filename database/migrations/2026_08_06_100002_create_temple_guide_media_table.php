<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_guide_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guide_id')
                ->constrained('temple_guides')
                ->cascadeOnDelete();
            $table->enum('media_type', ['photo', 'video'])->default('photo');
            $table->string('image_path', 500)->nullable(); // R2 key (photos)
            $table->string('video_url', 500)->nullable();  // YouTube / hosted link
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['guide_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_guide_media');
    }
};
