<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hall booking field unification — drop the three columns that web
     * collected but the mobile app + API never sent. Audit confirmed:
     *
     *   contact_email   — only used in one notification placeholder;
     *                     hall booking will rely on WhatsApp/SMS/push
     *                     instead. Devotee's email is still in the
     *                     devotee table if ever needed.
     *   aadhaar_number  — no business logic depends on it (admin
     *                     view-only field that mobile never populated).
     *   contact_address — same — admin view-only, mobile never populated.
     *
     * Devotee identity (name, phone) is still required at booking time
     * and lives on temple_hall_bookings + temple_devotees as before.
     */
    public function up(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table) {
            $table->dropColumn(['contact_email', 'aadhaar_number', 'contact_address']);
        });

        // Disable any pre-seeded hall-booking email template — its
        // recipient_value points at booking.contact_email which no
        // longer exists. Leave the row in place so historical state
        // (created_by, created_at) survives if the trust wants to
        // recreate it later with a different recipient strategy.
        \Illuminate\Support\Facades\DB::table('temple_notification_templates')
            ->where('key', 'hall.booking.confirmed')
            ->where('channel', 'email')
            ->update(['is_enabled' => false]);
    }

    public function down(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table) {
            $table->string('contact_email', 255)->nullable()->after('contact_phone');
            $table->string('aadhaar_number', 12)->nullable()->after('contact_email');
            $table->text('contact_address')->nullable()->after('aadhaar_number');
        });
    }
};
