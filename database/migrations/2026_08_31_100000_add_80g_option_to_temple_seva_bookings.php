<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-booking 80G opt-in for sevas.
 *
 * Mirrors the two-flag shape already used on temple_donations, and the
 * distinction is load-bearing in exactly the same way:
 *
 *   wants_80g       — the DEVOTEE'S REQUEST (the booking-form checkbox).
 *                     Never recomputed; it records what they asked for.
 *   is_80g_eligible — the SYSTEM'S VERDICT (wants_80g AND a decryptable,
 *                     format-valid PAN on the devotee profile).
 *
 * Both default FALSE here, unlike donations where `wants_80g` defaults
 * true. A seva is a service booking first; the tax receipt is the
 * exception, so an untouched checkbox must mean "plain seva receipt" —
 * which is also what every booking made before this migration got.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->boolean('wants_80g')->default(false)->after('receipt_path');
            $table->boolean('is_80g_eligible')->default(false)->after('wants_80g');
        });
    }

    public function down(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->dropColumn(['wants_80g', 'is_80g_eligible']);
        });
    }
};
