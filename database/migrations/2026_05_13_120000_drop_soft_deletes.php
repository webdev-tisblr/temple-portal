<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the SoftDeletes column from every model that had it. The trait
 * was added defensively across the catalogue but never actively used —
 * no code path queried withTrashed(), restored(), or forceDelete(). The
 * only real effect was admin "delete" clicks setting deleted_at instead
 * of removing the row, which the trust admin reported as "deletes don't
 * delete." Same root cause crashed OTP login (phone unique constraint
 * collided with an invisible tombstone).
 *
 * Order of operations per table:
 *   1. UPDATE … SET deleted_at = NULL for every soft-deleted row. This
 *      keeps the data visible in admin once the column is gone — admin
 *      can then choose to hard-delete them properly, which respects
 *      ON DELETE RESTRICT against donations / bookings / etc. (the
 *      whole reason FK strategy is RESTRICT in those tables).
 *   2. DROP COLUMN deleted_at.
 *
 * Reversible: the down() recreates the column on each table. Restored
 * rows can't be re-tombstoned automatically — that's not data loss,
 * just a one-way schema decision.
 */
return new class extends Migration {
    /** @var list<string> */
    private array $tables = [
        'temple_devotees',
        'temple_blog_posts',
        'temple_announcements',
        'temple_events',
        'temple_donation_campaigns',
        'temple_orders',
        'temple_donation_types',
        'temple_pages',
        'temple_product_categories',
        'temple_products',
        'temple_sevas',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            DB::table($table)->whereNotNull('deleted_at')->update(['deleted_at' => null]);
            Schema::table($table, fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }
            Schema::table($table, fn (Blueprint $t) => $t->softDeletes());
        }
    }
};
