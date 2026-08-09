<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Item 6.1 — prerequisite for putting `LogsActivity` on the money models.
 *
 * THE PROBLEM. `activity_log.subject_id` was created by Spatie's stock
 * migration as `nullableMorphs('subject')`, i.e. an UNSIGNED BIGINT. Until
 * now the only model using LogsActivity was AdminUser, whose primary key is
 * an auto-increment integer, so nobody noticed.
 *
 * Four of the five money models — Payment, Donation, SevaBooking and Order
 * — are UUID-keyed (`HasUuid`, char(36)). Logging one of those into a
 * bigint column does not fail cleanly, it fails in three different ways
 * depending on the UUID's leading characters and the connection's strict
 * mode: SQLSTATE[22003] out of range, SQLSTATE[01000] silent truncation, or
 * SQLSTATE[HY000] 1366 incorrect integer value. The truncation case is the
 * dangerous one — it writes a WRONG subject_id and the audit trail quietly
 * points at the wrong row.
 *
 * THE FIX, and why it is this one. Spatie's documented answer for UUID
 * subjects is to edit the published migration's `nullableMorphs('subject')`
 * into `nullableUuidMorphs('subject')` (char(36)); there is no config
 * switch, and the custom-`activity_model` hook the package offers changes
 * behaviour, not column types. That documented route assumes EVERY subject
 * is a UUID, which is not this platform: the subject column is polymorphic
 * and shared, and it must keep serving AdminUser (auto-increment int) and
 * HallBooking (bigint) alongside the four UUID models. VARCHAR(36) is
 * therefore the same change generalised — it holds a 36-char UUID and a
 * stringified integer equally well, where char(36) would right-pad the
 * integers and uuid/binary would reject them outright.
 *
 * `causer_id` needs exactly the same treatment, for a less obvious reason.
 * Spatie resolves the causer from the AUTHENTICATED user, and that is not
 * always an AdminUser: a devotee downloading their own 80G receipt hits
 * ReceiptService, which writes the strict-80G verdict back onto the
 * Donation — so the causer is a Devotee, whose key is also a char(36)
 * UUID. Missing this turns a devotee-initiated receipt download into a
 * 500 (SQLSTATE[22003] on causer_id), which is precisely how it first
 * surfaced.
 *
 * EXISTING DATA / DOWNTIME. Safe in place. Widening an integer column to a
 * string is non-lossy: MySQL renders each value as the same digits, and
 * Eloquent's morph lookup binds the model key and compares it the same way,
 * so AdminUser's existing rows keep resolving. Done as a raw MODIFY rather
 * than a Blueprint ->change() because the column sits inside the composite
 * `subject` index, and MySQL alters it in place without the index having to
 * be dropped and rebuilt. MySQL 8 performs this as an INPLACE ALTER that
 * takes only a brief metadata lock, so NO DOWNTIME is required — though on
 * a large `activity_log` it is still worth running off-peak. The guard at
 * the top makes a re-run a no-op.
 */
return new class extends Migration
{
    private function connection(): ?string
    {
        return config('activitylog.database_connection');
    }

    private function table(): string
    {
        return (string) config('activitylog.table_name', 'activity_log');
    }

    public function up(): void
    {
        $schema = Schema::connection($this->connection());

        if (! $schema->hasTable($this->table())) {
            return;
        }

        foreach (['subject_id', 'causer_id'] as $column) {
            if ($schema->getColumnType($this->table(), $column) === 'string') {
                continue;
            }

            DB::connection($this->connection())
                ->statement('ALTER TABLE `'.$this->table().'` MODIFY `'.$column.'` VARCHAR(36) NULL');
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection());

        if (! $schema->hasTable($this->table())) {
            return;
        }

        // A UUID cannot be represented as a bigint, so the rows that only
        // exist BECAUSE of this migration are dropped rather than silently
        // mangled into wrong integers on the way back.
        foreach (['subject_id', 'causer_id'] as $column) {
            DB::connection($this->connection())
                ->table($this->table())
                ->whereNotNull($column)
                ->whereRaw("{$column} NOT REGEXP '^[0-9]+$'")
                ->delete();
        }

        foreach (['subject_id', 'causer_id'] as $column) {
            DB::connection($this->connection())
                ->statement('ALTER TABLE `'.$this->table().'` MODIFY `'.$column.'` BIGINT UNSIGNED NULL');
        }
    }
};
