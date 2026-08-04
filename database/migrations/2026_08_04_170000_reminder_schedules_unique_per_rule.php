<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The old unique index (seva_booking_id, offset) allowed only ONE
 * reminder row per offset per booking — but the trust legitimately
 * configures several rules at the same offset (devotee + assignee +
 * custom phone, all "1 day before"). Only the first rule ever got a
 * row; the rest silently never fired (and, before the 2026-08-04
 * hotfix, collided hard enough to roll back payment captures).
 *
 * New key: (seva_booking_id, rule_id, offset) — one row per rule.
 * Legacy offset-fallback rows carry rule_id NULL; MySQL permits
 * repeated NULLs in unique keys, so their dedupe stays search-based
 * in the scheduler (as before).
 *
 * Order matters: ADD the new index before DROPPING the old one — the
 * seva_booking_id FK needs a covering index at all times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_seva_reminder_schedules', function (Blueprint $table) {
            $table->unique(['seva_booking_id', 'rule_id', 'offset'], 'tsrs_booking_rule_offset_unique');
        });
        Schema::table('temple_seva_reminder_schedules', function (Blueprint $table) {
            $table->dropUnique('temple_seva_reminder_schedules_seva_booking_id_offset_unique');
        });
    }

    public function down(): void
    {
        Schema::table('temple_seva_reminder_schedules', function (Blueprint $table) {
            $table->unique(['seva_booking_id', 'offset'], 'temple_seva_reminder_schedules_seva_booking_id_offset_unique');
        });
        Schema::table('temple_seva_reminder_schedules', function (Blueprint $table) {
            $table->dropUnique('tsrs_booking_rule_offset_unique');
        });
    }
};
