<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-computed seva reminder schedule.
 *
 * Previously DispatchSevaReminders recalculated, on every 5-minute tick,
 * which (booking × offset) pairs were due — by scanning ALL confirmed
 * upcoming bookings and checking whether each offset's fire moment landed
 * inside the last 5 minutes. That had two problems:
 *
 *   • A missed tick (deploy, server blip, an over-long previous run)
 *     meant the fire moment passed and was NEVER revisited — the reminder
 *     was silently lost.
 *   • Every tick re-scanned every upcoming booking even when nothing was
 *     due.
 *
 * Now each reminder is materialised as a row the moment a booking is
 * confirmed (SevaReminderScheduler, called from SevaBookingObserver). The
 * cron simply asks "any pending rows whose fire_at has passed?" — so a
 * reminder missed while the cron was down is still pending and goes out a
 * little late instead of never, and the query touches only due rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_seva_reminder_schedules', function (Blueprint $table) {
            $table->id();
            // temple_seva_bookings.id is a UUID (char 36) — must be foreignUuid.
            $table->foreignUuid('seva_booking_id');
            // The raw offset token from temple_sevas.reminder_offsets, eg "12h".
            $table->string('offset', 16);
            // The absolute moment this reminder should fire (seva moment − offset).
            $table->timestamp('fire_at');
            // pending → sent (dispatched at fire time) | skipped (booking no
            // longer confirmed, or too stale to be useful) | failed (dispatch
            // itself errored — rare; visible for inspection).
            $table->string('status', 16)->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->foreign('seva_booking_id')
                ->references('id')->on('temple_seva_bookings')
                ->cascadeOnDelete();

            // One row per (booking, offset) — makes generation idempotent
            // (firstOrCreate) and guarantees a reminder can't be duplicated.
            $table->unique(['seva_booking_id', 'offset']);
            // The cron's only query: pending rows whose fire_at has passed.
            $table->index(['status', 'fire_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_seva_reminder_schedules');
    }
};
