<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 5.4 — strict 80G, plus the receipt-snapshot half of item 5.2.
 *
 * Two independent columns on temple_donations, because "what the donor
 * asked for" and "what the trust is legally able to issue" are different
 * facts and conflating them is exactly how `is_80g_eligible` ended up
 * hardcoded `true` on every row while meaning nothing:
 *
 *   wants_80g        — the DONOR'S REQUEST. Set from the checkout
 *                      checkbox, never recomputed afterwards.
 *   is_80g_eligible  — the SYSTEM'S VERDICT (wants_80g AND a decryptable,
 *                      format-valid PAN on the devotee profile). This is
 *                      what the Flutter receipts screen already filters on
 *                      (receipts_screen.dart), so it must be truthful.
 *
 * And on temple_receipts_80g: campaign_title / donation_purpose are
 * SNAPSHOTS. A receipt is a statutory document and its PDF is only a
 * regenerable cache (the nightly sweep NULLs pdf_path), so anything
 * resolved live through a relation silently rewrites already-issued
 * receipts the next time they re-render. Renaming a campaign in admin
 * must not change a receipt that is already in a donor's hands.
 *
 * Historical rows are backfilled from the live relation ONCE, here —
 * for receipts issued before this column existed the live value is the
 * only value that exists, and it is strictly better than a blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_donations', function (Blueprint $table) {
            // Default true so any code path that does not know about the
            // checkbox yet (admin manual entry, older API clients) keeps
            // the previous "ask for a receipt" behaviour. The PAN gate,
            // not this column, is what actually withholds the receipt.
            $table->boolean('wants_80g')->default(true)->after('is_80g_eligible');
        });

        Schema::table('temple_receipts_80g', function (Blueprint $table) {
            $table->string('campaign_title', 255)->nullable()->after('financial_year');
            $table->string('donation_purpose', 500)->nullable()->after('campaign_title');
        });

        // Backfill the snapshots from the live relation for rows issued
        // before the columns existed. English title preferred — the 80G
        // receipt is a deliberately English-only document.
        DB::statement(<<<'SQL'
            UPDATE temple_receipts_80g r
            JOIN temple_donations d ON d.id = r.donation_id
            LEFT JOIN temple_donation_campaigns c ON c.id = d.campaign_id
            SET r.campaign_title = NULLIF(TRIM(COALESCE(NULLIF(TRIM(c.title_en), ''), c.title_gu, '')), ''),
                r.donation_purpose = NULLIF(TRIM(COALESCE(d.purpose, '')), '')
        SQL);

        // Existing rows all predate the checkbox; every one of them was
        // created with the hardcoded is_80g_eligible = true, so "the donor
        // wanted a receipt" is the honest reading. The verdict column is
        // left alone — this migration deliberately does NOT retro-judge
        // already-issued receipts (see the legal note in spec 05).
        DB::table('temple_donations')->update(['wants_80g' => true]);
    }

    public function down(): void
    {
        Schema::table('temple_receipts_80g', function (Blueprint $table) {
            $table->dropColumn(['campaign_title', 'donation_purpose']);
        });

        Schema::table('temple_donations', function (Blueprint $table) {
            $table->dropColumn('wants_80g');
        });
    }
};
