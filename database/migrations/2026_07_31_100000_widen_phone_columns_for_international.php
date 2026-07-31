<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * International numbers are stored as full E.164 digits without '+'
 * (max 15 chars). Widen the tightest phone columns to 20 for headroom;
 * existing bare-10-digit Indian rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_devotees', function (Blueprint $table) {
            $table->string('phone', 20)->change();
        });

        Schema::table('temple_otp_codes', function (Blueprint $table) {
            $table->string('phone', 20)->change();
        });

        Schema::table('temple_hall_bookings', function (Blueprint $table) {
            $table->string('contact_phone', 20)->change();
        });

        Schema::table('temple_contact_submissions', function (Blueprint $table) {
            $table->string('phone', 20)->change();
        });
    }

    public function down(): void
    {
        // Narrowing back risks truncating stored international numbers —
        // leave the widened columns in place.
    }
};
