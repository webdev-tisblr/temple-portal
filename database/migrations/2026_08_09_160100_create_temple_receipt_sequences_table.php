<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Defect A — a real sequence for the statutory 80G receipt number.
 *
 * ReceiptService used to compute the serial as
 *
 *     Receipt80G::where('financial_year', $fy)->count() + 1
 *
 * with no lock and no transaction. Two things were wrong with that:
 *
 *   1. Two concurrent captures in the same financial year read the same
 *      COUNT, built the same receipt number, and one of them died on the
 *      `receipt_number` UNIQUE index. At the Aug-15 launch concurrency
 *      target that is not theoretical.
 *   2. COUNT is derived from rows that still EXIST. Delete any receipt row
 *      and the next allocation re-issues a number that has already been
 *      printed, emailed and WhatsApped to a donor — and then collides.
 *
 * A dedicated counter fixes both: it is taken under `lockForUpdate()` so
 * allocation is serialised, and it only ever moves forward, so a deleted
 * row can never hand its number to somebody else. Gaps are acceptable
 * (a rolled-back generation burns a number); reuse is not.
 *
 * Backfilled from the highest serial actually present per financial year,
 * parsed out of the existing `{prefix}/{fy}/{serial}` receipt numbers, so
 * the first allocation after this migration continues the live sequence
 * instead of restarting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_receipt_sequences', function (Blueprint $table) {
            // The FY string IS the identity — one counter per year, and
            // the primary key is what lockForUpdate() serialises on.
            $table->string('financial_year', 7)->primary();
            $table->unsignedInteger('last_serial')->default(0);
            $table->timestamps();
        });

        // SUBSTRING_INDEX(..., '/', -1) takes the trailing serial segment
        // of "SPHST/80G/2026-27/00007". CAST … UNSIGNED strips the zero
        // padding. MAX (not COUNT) so gaps in the historical data cannot
        // hand out a burnt number.
        $rows = DB::select(<<<'SQL'
            SELECT financial_year,
                   MAX(CAST(SUBSTRING_INDEX(receipt_number, '/', -1) AS UNSIGNED)) AS max_serial,
                   COUNT(*) AS row_count
            FROM temple_receipts_80g
            GROUP BY financial_year
        SQL);

        foreach ($rows as $row) {
            DB::table('temple_receipt_sequences')->insert([
                'financial_year' => $row->financial_year,
                // Belt and braces: if a legacy receipt number ever failed
                // to parse (max_serial = 0) fall back to the row count, so
                // we still start above everything already issued.
                'last_serial' => max((int) $row->max_serial, (int) $row->row_count),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_receipt_sequences');
    }
};
