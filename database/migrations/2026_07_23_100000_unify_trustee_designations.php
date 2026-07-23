<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Trust decision (2026-07-23): all trustees carry the single designation
 * "Trustee" in every language — the per-person roles (પ્રમુખ/સચિવ/કોષાધ્યક્ષ)
 * are retired. Trustee::booted() forces the same values on every future
 * save; this backfills the existing rows so web, admin AND already-shipped
 * app builds (which read role_* from /api/v1/content/trustees) all show
 * the uniform designation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('temple_trustees')->update([
            'role_gu' => 'ટ્રસ્ટી',
            'role_hi' => 'ट्रस्टी',
            'role_en' => 'Trustee',
        ]);

        // The trustees API payload is cached per-locale for 30 min.
        \App\Support\LocalizedCache::forget('content.trustees.v1');
    }

    public function down(): void
    {
        // Old per-person designations are not restorable; nothing to do.
    }
};
