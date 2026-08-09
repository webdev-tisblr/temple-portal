<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Section 4 (2026-08-09) — multi-day hall bookings (4.2) + admin
 * configurable booking cut-off (4.3).
 *
 * `booking_date` DELIBERATELY keeps its name and becomes the RANGE START.
 * Renaming it would break the live Meta-approved WhatsApp templates that
 * bind {{n}} positionally to the `booking_date` placeholder, the invoice
 * blade, Filament, the shipped app build (1.4.8+32) and the API contract.
 *
 * Seva cut-off does NOT get a column: it lives inside `slot_config` JSON so
 * sevas sharing a slot pool inherit it through SevaSlotService::configFor().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table) {
            // Nullable first so the backfill below can run on a live table
            // without a window where existing rows violate NOT NULL.
            $table->date('end_date')->nullable()->after('booking_date');
            // Denormalized on purpose: pricing is price_per_day × days and
            // price_per_day is admin-mutable, so the invoice must stay
            // reproducible years later. total_amount stays authoritative.
            $table->unsignedSmallInteger('days_count')->default(1)->after('end_date');
        });

        // Backfill every pre-existing single-day booking.
        DB::statement('UPDATE temple_hall_bookings SET end_date = booking_date, days_count = 1 WHERE end_date IS NULL');

        Schema::table('temple_hall_bookings', function (Blueprint $table) {
            $table->date('end_date')->nullable(false)->change();
            $table->index(['hall_id', 'booking_date', 'end_date'], 'hb_hall_range_idx');
        });

        Schema::table('temple_halls', function (Blueprint $table) {
            // 1 preserves today's behaviour EXACTLY — multi-day is opt-in
            // per hall, which doubles as the admin kill switch.
            $table->unsignedSmallInteger('max_booking_days')->default(1)->after('capacity');
            // Hours before the booking start during which devotees may no
            // longer book. 0 = no cut-off (today's behaviour).
            $table->unsignedSmallInteger('booking_cutoff_hours')->default(0)->after('max_booking_days');
            // A hall booking has no start time, so an hours-based cut-off
            // needs an anchor. 09:00 mirrors SevaSlotService's full-day
            // anchor default.
            $table->time('day_start_time')->default('09:00:00')->after('booking_cutoff_hours');
        });
    }

    public function down(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table) {
            $table->dropIndex('hb_hall_range_idx');
            $table->dropColumn(['end_date', 'days_count']);
        });

        Schema::table('temple_halls', function (Blueprint $table) {
            $table->dropColumn(['max_booking_days', 'booking_cutoff_hours', 'day_start_time']);
        });
    }
};
