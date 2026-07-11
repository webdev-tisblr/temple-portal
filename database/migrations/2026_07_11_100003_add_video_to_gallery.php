<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let the gallery hold video links alongside photos. Videos have no
 * uploaded image, so image_path becomes nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_gallery_images', function (Blueprint $table) {
            $table->enum('type', ['photo', 'video'])->default('photo')->after('id');
            $table->string('video_url', 500)->nullable()->after('medium_path');
            $table->string('image_path', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('temple_gallery_images', function (Blueprint $table) {
            $table->dropColumn(['type', 'video_url']);
            // Note: image_path left nullable — reverting to NOT NULL could fail
            // if video rows exist. Safe to leave nullable.
        });
    }
};
