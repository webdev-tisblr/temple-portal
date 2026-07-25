<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keep old page slugs working after an admin renames a page.
 *
 * The seeded History page shipped as slug `history`; it was later renamed
 * to `itihas` in the CMS. Nothing carried the old slug forward, so the
 * mobile app's hardcoded /pages/history/embed link started 404ing — and
 * every link already shared over WhatsApp died with it. Page::booted now
 * records the outgoing slug here on every rename, and PageController
 * resolves against it, so this can't silently break again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_pages', function (Blueprint $table) {
            $table->json('previous_slugs')->nullable()->after('slug');
        });

        // Backfill the one rename that already happened, so installed apps
        // recover without waiting for a store release. Guarded: only when
        // `history` is genuinely free and exactly one page claims the
        // English title it was seeded with.
        if (DB::table('temple_pages')->where('slug', 'history')->exists()) {
            return;
        }

        $candidates = DB::table('temple_pages')
            ->where('title_en', 'History')
            ->pluck('slug');

        if ($candidates->count() === 1) {
            DB::table('temple_pages')
                ->where('slug', $candidates->first())
                ->update(['previous_slugs' => json_encode(['history'])]);
        }
    }

    public function down(): void
    {
        Schema::table('temple_pages', function (Blueprint $table) {
            $table->dropColumn('previous_slugs');
        });
    }
};
