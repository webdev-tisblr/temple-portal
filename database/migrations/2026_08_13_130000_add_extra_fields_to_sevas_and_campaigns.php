<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dynamic extra fields for sevas and campaigns (2026-08-13).
 *
 * DonationType has carried `extra_fields` — an admin-defined form builder whose
 * values land in `donations.extra_data` and can be placed on a greeting card —
 * since April. Sevas and campaigns had no equivalent, so their cards could only
 * show built-in variables: no "whose birthday is it", no donor photo.
 *
 * Campaigns need no storage column: a campaign donation is still a Donation, so
 * its answers reuse `temple_donations.extra_data`. Seva bookings are a separate
 * table and get their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table): void {
            // Same shape as temple_donation_types.extra_fields: a list of
            // {key, label_gu, label_hi, label_en, type, required}.
            $table->json('extra_fields')->nullable()->after('slot_config');
        });

        Schema::table('temple_donation_campaigns', function (Blueprint $table): void {
            $table->json('extra_fields')->nullable()->after('greeting_card_config');
        });

        Schema::table('temple_seva_bookings', function (Blueprint $table): void {
            // The devotee's answers. Mirrors temple_donations.extra_data,
            // including image fields, which store the R2 key of the upload
            // rather than the bytes.
            $table->json('extra_data')->nullable()->after('sankalp');
        });
    }

    public function down(): void
    {
        Schema::table('temple_seva_bookings', function (Blueprint $table): void {
            $table->dropColumn('extra_data');
        });

        Schema::table('temple_donation_campaigns', function (Blueprint $table): void {
            $table->dropColumn('extra_fields');
        });

        Schema::table('temple_sevas', function (Blueprint $table): void {
            $table->dropColumn('extra_fields');
        });
    }
};
