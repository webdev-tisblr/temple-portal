<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * slot_time was created as a TIME column, but the full-day / full-week
 * booking modes store the sentinel strings 'full_day' / 'full_week' in it
 * (the day/week IS the slot). MySQL rejects those in a TIME column with
 * "Incorrect time value: 'full_day'", so every full-day booking fails at
 * insert. Widen the column to a short VARCHAR that holds both an "HH:MM"
 * time slot and the sentinels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->string('slot_time', 20)->nullable()->change();
        });

        // Existing rows converted from TIME come across as 'HH:MM:SS'.
        // Normalise them to the 'HH:MM' form the app sends and the
        // capacity queries compare against, so old and new rows match.
        DB::statement(
            "UPDATE temple_seva_bookings SET slot_time = SUBSTRING(slot_time, 1, 5) WHERE slot_time LIKE '__:__:__'"
        );
    }

    public function down(): void
    {
        // Sentinels can't live in a TIME column — blank them before the
        // type reverts so the change doesn't fail on existing full-day rows.
        DB::statement(
            "UPDATE temple_seva_bookings SET slot_time = NULL WHERE slot_time IN ('full_day', 'full_week')"
        );

        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->time('slot_time')->nullable()->change();
        });
    }
};
