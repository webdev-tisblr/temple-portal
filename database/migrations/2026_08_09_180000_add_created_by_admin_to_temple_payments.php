<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Item 6.1 — manual cash entry audit trail.
 *
 * WHY ONLY ON temple_payments. A counter entry always produces exactly one
 * Payment row, whatever the record type, and the Payment is where the money
 * fact lives. Stamping the admin here answers the reconciliation question
 * the trust actually asks ("who took this cash?") from a single table,
 * instead of four.
 *
 * `created_by_admin_id` rather than the project's older `created_by`
 * convention (Event / Page / Notification / SystemSetting): those are bare
 * unconstrained integers, and on a money table an explicit, constrained FK
 * is worth the extra characters.
 *
 * nullOnDelete: an admin leaving the trust must never cascade away a
 * payment row. The human string in Payment.description survives as the
 * belt-and-braces trace once the FK is NULLed.
 *
 * NULL means "not a counter entry" (every online payment) — the column is
 * deliberately nullable, and no backfill is attempted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('temple_payments', 'created_by_admin_id')) {
            return;
        }

        Schema::table('temple_payments', function (Blueprint $table) {
            $table->foreignId('created_by_admin_id')
                ->nullable()
                ->after('paid_at')
                ->constrained('temple_admin_users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('temple_payments', 'created_by_admin_id')) {
            return;
        }

        Schema::table('temple_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_admin_id');
        });
    }
};
