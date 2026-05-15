<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Final shape of the per-seva reminder config — just a list of
     * offsets. Each reminder fires `seva.booking.reminder` once; the
     * NotificationService fans out across every enabled template
     * (devotee, admin role, etc.) per the template's recipient strategy.
     *
     * The previous shape (with per-row recipient checkboxes) was
     * conflating scheduling with audience selection — audience is the
     * NotificationTemplate's job, not the Seva's.
     *
     * Stores ["168h", "72h", "24h", "12h", "3h"] etc.
     */
    public function up(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropColumn('reminders');
        });

        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->json('reminder_offsets')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropColumn('reminder_offsets');
        });

        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->json('reminders')->nullable();
        });
    }
};
