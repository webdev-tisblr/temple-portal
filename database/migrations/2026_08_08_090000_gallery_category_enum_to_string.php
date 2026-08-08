<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * temple_gallery_images.category was created as
 * enum('temple','deity','festival','event','wallpaper','other') and never
 * widened, but categories became admin-managed rows in
 * temple_gallery_categories on 2026-08-04.
 *
 * So any category the trust creates itself is unassignable: MySQL rejects the
 * value outright. 'annkshetra-opening-2011' already exists in production and
 * could not be put on a single photo.
 *
 * Widen to a plain string. No data changes — every existing value is a valid
 * string — and the index on the column is preserved by MODIFY.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE `temple_gallery_images` MODIFY `category` VARCHAR(100) NOT NULL DEFAULT 'temple'");
    }

    public function down(): void
    {
        // Anything the admin created since would not fit the old enum, so park
        // those rows on 'other' first rather than letting MySQL truncate them.
        DB::table('temple_gallery_images')
            ->whereNotIn('category', ['temple', 'deity', 'festival', 'event', 'wallpaper', 'other'])
            ->update(['category' => 'other']);

        DB::statement("ALTER TABLE `temple_gallery_images` MODIFY `category` ENUM('temple','deity','festival','event','wallpaper','other') NOT NULL DEFAULT 'temple'");
    }
};
