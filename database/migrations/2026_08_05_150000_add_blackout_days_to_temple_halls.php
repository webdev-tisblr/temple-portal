<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring weekday blockouts for halls — block EVERY Monday/Tuesday/…
 * (list of lowercase weekday names), alongside the specific-date
 * blackout_dates added earlier the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_halls', function (Blueprint $table) {
            $table->json('blackout_days')->nullable()->after('blackout_dates');
        });
    }

    public function down(): void
    {
        Schema::table('temple_halls', function (Blueprint $table) {
            $table->dropColumn('blackout_days');
        });
    }
};
