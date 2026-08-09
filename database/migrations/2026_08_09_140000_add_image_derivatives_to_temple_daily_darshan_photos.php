<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily darshan photos get the same thumbnail/medium rendition columns the
 * gallery already had, so the app's darshan card stops decoding a
 * full-resolution original. Nullable + backfilled by
 * `php artisan images:backfill-derivatives`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_daily_darshan_photos', function (Blueprint $table) {
            if (! Schema::hasColumn('temple_daily_darshan_photos', 'thumbnail_path')) {
                $table->string('thumbnail_path', 500)->nullable()->after('image_path');
            }
            if (! Schema::hasColumn('temple_daily_darshan_photos', 'medium_path')) {
                $table->string('medium_path', 500)->nullable()->after('thumbnail_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('temple_daily_darshan_photos', function (Blueprint $table) {
            $table->dropColumn(['thumbnail_path', 'medium_path']);
        });
    }
};
