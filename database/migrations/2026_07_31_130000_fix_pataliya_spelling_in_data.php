<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The English trust/temple name was misspelled "Pataliya" — correct is
 * "Patadiya" (matching patadiyahanumanji.com). Code fallbacks, lang
 * files and seeders are fixed in the same deploy; this sweeps the
 * misspelling out of DATA:
 *
 *   - temple_system_settings values (trust_name, mail_from_name, …)
 *   - temple_receipts_80g.trust_name — snapshotted at receipt creation,
 *     so old receipts would regenerate with the typo forever otherwise
 *   - seeded English content (events / products / CMS pages)
 *   - admin-authored notification template wording
 *
 * Gujarati (પાતાળિયા) and Hindi spellings are correct and untouched —
 * only the Latin-script word is replaced. The logo filename
 * (shree-pataliya-hanumanji-logo.png) is intentionally left alone, so
 * image_path-style columns are excluded.
 */
return new class extends Migration
{
    private const TARGETS = [
        'temple_system_settings' => ['value'],
        'temple_receipts_80g' => ['trust_name'],
        'temple_events' => ['title_en', 'description_en', 'location'],
        'temple_products' => ['name_en', 'description_en'],
        'temple_pages' => ['title_en', 'body_en', 'meta_title', 'meta_description', 'blocks_gu', 'blocks_hi', 'blocks_en'],
        'temple_notification_templates' => ['subject', 'body', 'from_name'],
    ];

    public function up(): void
    {
        // Column DEFAULT set at table creation also carries the typo.
        if (Schema::hasTable('temple_receipts_80g')) {
            DB::statement("ALTER TABLE `temple_receipts_80g` ALTER COLUMN `trust_name` SET DEFAULT 'Shree Patadiya Hanumanji Seva Trust'");
        }

        foreach (self::TARGETS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                DB::table($table)
                    ->whereNotNull($column)
                    ->where($column, 'like', '%Pataliya%')
                    ->update([$column => DB::raw("REPLACE(`{$column}`, 'Pataliya', 'Patadiya')")]);
            }
        }
    }

    public function down(): void
    {
        // Spelling fix — no rollback.
    }
};
