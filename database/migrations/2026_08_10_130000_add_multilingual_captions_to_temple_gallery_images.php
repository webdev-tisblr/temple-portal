<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gallery photo captions go multilingual (gu / hi / en).
 *
 * The legacy `title` and `description` columns are deliberately KEPT rather
 * than dropped:
 *
 *  - The shipped app build (1.4.8+32) reads `title` / `description` straight
 *    off the API payload. Dropping the columns — or changing their shape —
 *    would blank the caption on every phone that has not updated.
 *  - They stay populated as the Gujarati mirror (see GalleryImage::booted(),
 *    which re-mirrors `*_gu` into them on every save), so the two never drift.
 *
 * The backfill below moves any existing value into the Gujarati column, since
 * everything authored so far was written in Gujarati. Production had 0 titled
 * rows at the time of writing; local/dev data does, and rehearsing against
 * populated rows is the point (project memory: empty tables hide row bugs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_gallery_images', function (Blueprint $table) {
            $table->string('title_gu', 255)->nullable()->after('title');
            $table->string('title_hi', 255)->nullable()->after('title_gu');
            $table->string('title_en', 255)->nullable()->after('title_hi');
            $table->string('description_gu', 500)->nullable()->after('description');
            $table->string('description_hi', 500)->nullable()->after('description_gu');
            $table->string('description_en', 500)->nullable()->after('description_hi');
        });

        // Preserve whatever an admin already typed. Guarded on the new column
        // being empty so a re-run (or a partially applied deploy) can never
        // clobber a real Gujarati translation with the legacy mirror.
        DB::table('temple_gallery_images')
            ->whereNotNull('title')
            ->where('title', '!=', '')
            ->whereNull('title_gu')
            ->update(['title_gu' => DB::raw('`title`')]);

        DB::table('temple_gallery_images')
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->whereNull('description_gu')
            ->update(['description_gu' => DB::raw('`description`')]);
    }

    public function down(): void
    {
        // `title` / `description` still hold the Gujarati text, so dropping
        // these six columns loses only the hi/en translations.
        Schema::table('temple_gallery_images', function (Blueprint $table) {
            $table->dropColumn([
                'title_gu', 'title_hi', 'title_en',
                'description_gu', 'description_hi', 'description_en',
            ]);
        });
    }
};
