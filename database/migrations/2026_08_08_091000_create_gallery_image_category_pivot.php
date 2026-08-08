<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets one photo belong to several gallery categories.
 *
 * The pivot keys on the category SLUG rather than an id, matching the existing
 * loose convention (GalleryCategory::images() already joined on slug) and
 * making the backfill a straight copy of the current column.
 *
 * temple_gallery_images.category STAYS, holding the primary category. It is
 * not redundant: the installed mobile app parses it with a hard
 * `json['category'] as String?` cast, so it must remain a scalar in the API
 * response or the app's gallery silently goes empty for every user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_gallery_image_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_image_id')
                ->constrained('temple_gallery_images')
                ->cascadeOnDelete();
            $table->string('category_slug', 100);

            $table->unique(['gallery_image_id', 'category_slug'], 'gallery_image_category_unique');
            $table->index('category_slug');
        });

        // Every existing photo keeps exactly the category it has today.
        DB::statement('
            INSERT INTO `temple_gallery_image_category` (`gallery_image_id`, `category_slug`)
            SELECT `id`, `category` FROM `temple_gallery_images`
            WHERE `category` IS NOT NULL AND `category` != ""
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_gallery_image_category');
    }
};
