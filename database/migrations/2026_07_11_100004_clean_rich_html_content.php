<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time sweep of existing RichEditor content: normalise pasted &nbsp;
 * padding / empty paragraphs via clean_rich_html() (app/Helpers/storage.php).
 * Only whitespace is touched — words and markup are preserved. New saves are
 * cleaned automatically by the RichEditor configureUsing hook in
 * AppServiceProvider, so this only needs to run once.
 *
 * Data migration per repo convention (deploy takes a DB snapshot first).
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => rich-text columns */
    private array $targets = [
        'temple_sevas' => ['description_gu', 'description_hi', 'description_en'],
        'temple_products' => ['description_gu', 'description_hi', 'description_en'],
        'temple_halls' => ['description_gu', 'description_hi', 'description_en'],
        'temple_events' => ['description_gu', 'description_hi', 'description_en'],
        'temple_blog_posts' => ['body_gu', 'body_hi', 'body_en'],
        'temple_announcements' => ['body_gu', 'body_hi', 'body_en'],
        'temple_pages' => ['body_gu', 'body_hi', 'body_en'],
        'temple_donation_campaigns' => ['writeup_gu', 'writeup_hi', 'writeup_en'],
    ];

    public function up(): void
    {
        foreach ($this->targets as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_filter(
                $columns,
                fn (string $c) => Schema::hasColumn($table, $c),
            ));
            if ($columns === []) {
                continue;
            }

            DB::table($table)
                ->select(array_merge(['id'], $columns))
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($table, $columns) {
                    foreach ($rows as $row) {
                        $updates = [];
                        foreach ($columns as $col) {
                            $orig = $row->{$col};
                            if (! is_string($orig) || $orig === '') {
                                continue;
                            }
                            $clean = clean_rich_html($orig);
                            if ($clean !== $orig) {
                                $updates[$col] = $clean;
                            }
                        }
                        if ($updates !== []) {
                            DB::table($table)->where('id', $row->id)->update($updates);
                        }
                    }
                });
        }

        // Temple rules live as SystemSetting rows, not columns.
        if (Schema::hasTable('temple_system_settings')) {
            $keys = ['temple_rules', 'temple_rules_gu', 'temple_rules_hi', 'temple_rules_en'];
            foreach (DB::table('temple_system_settings')->whereIn('key', $keys)->get() as $row) {
                if (! is_string($row->value) || $row->value === '') {
                    continue;
                }
                $clean = clean_rich_html($row->value);
                if ($clean !== $row->value) {
                    DB::table('temple_system_settings')->where('id', $row->id)->update(['value' => $clean]);
                }
            }
        }
    }

    public function down(): void
    {
        // Whitespace normalisation is not reversible (and shouldn't be).
    }
};
