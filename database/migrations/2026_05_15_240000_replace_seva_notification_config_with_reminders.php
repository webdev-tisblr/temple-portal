<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Seva notification configuration cleanup.
     *
     * The previous `notification_config` JSON column was a blob
     * containing channels + message + reminders all mixed together,
     * and nothing in the codebase actually consumed it (the UI helper
     * text literally said "actual sending will be handled by a
     * scheduled job" — the job never existed). Replaced by a single
     * structured `reminders` JSON whose only job is the schedule.
     *
     * Architecture (post-cleanup):
     *   • What does the message say + which channels?
     *     → Communication → Notification Templates
     *       (seva.booking.confirmed, seva.booking.staff_alert,
     *        seva.booking.reminder.devotee, seva.booking.reminder.staff)
     *   • When does each reminder fire + who gets it?
     *     → this `reminders` column on each Seva row.
     *
     * Shape of `reminders` (array of objects):
     *   [
     *     {"offset": "24h", "recipients": ["devotee", "staff"]},
     *     {"offset": "3h",  "recipients": ["devotee"]}
     *   ]
     *
     * Offset is a relative time string parsed by the cron command:
     *   minutes (e.g. "30m"), hours ("3h", "12h"), days ("1d", "7d").
     */
    public function up(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropColumn('notification_config');
        });

        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->json('reminders')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropColumn('reminders');
        });

        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->json('notification_config')->nullable();
        });
    }
};
