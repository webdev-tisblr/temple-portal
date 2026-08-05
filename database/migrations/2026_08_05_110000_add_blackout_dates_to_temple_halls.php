<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed hall blockout dates — mirrors the seva blackout_dates
 * pattern (list of {date, reason}), but as a real column since halls
 * have no slot_config JSON. Blocked dates are unavailable for booking
 * on web + app regardless of existing bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_halls', function (Blueprint $table) {
            $table->json('blackout_dates')->nullable()->after('amenities');
        });
    }

    public function down(): void
    {
        Schema::table('temple_halls', function (Blueprint $table) {
            $table->dropColumn('blackout_dates');
        });
    }
};
