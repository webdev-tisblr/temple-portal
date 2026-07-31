<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-seva opt-in: when the day's Daily Darshan photo is uploaded,
 * devotees holding a confirmed booking of this seva for that date get
 * the photo via the darshan.photo.uploaded notification trigger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->boolean('send_darshan_on_booking_date')->default(false)->after('reminder_mode');
        });
    }

    public function down(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropColumn('send_darshan_on_booking_date');
        });
    }
};
