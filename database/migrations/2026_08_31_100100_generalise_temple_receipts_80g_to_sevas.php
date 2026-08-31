<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Let a statutory 80G receipt hang off a SEVA BOOKING as well as a
 * donation.
 *
 * The alternative — re-synthesising a Donation row per 80G seva booking —
 * is exactly what was removed on 2026-05-13, and it double-counted every
 * such payment in the donation totals, the dashboards and the financial
 * reports. So the receipt table grows a second, mutually exclusive source
 * instead.
 *
 * `donation_id` becomes NULLABLE (it was NOT NULL + unique + FK). Its
 * unique index and foreign key both survive: MySQL unique indexes ignore
 * NULLs, so any number of seva receipts can sit alongside the donation
 * ones without colliding, and a NULL always satisfies a foreign key.
 *
 * `seva_booking_id` is char(36) via foreignUuid() — temple_seva_bookings.id
 * is a UUID, and foreignId() here would silently create a BIGINT that can
 * never match.
 *
 * The receipt NUMBER series is deliberately NOT split. Both sources draw
 * from the same temple_receipt_sequences counter, so the trust files one
 * continuous per-year sequence (what Form 10BD expects) rather than two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_receipts_80g', function (Blueprint $table) {
            $table->dropForeign(['donation_id']);
        });

        Schema::table('temple_receipts_80g', function (Blueprint $table) {
            $table->uuid('donation_id')->nullable()->change();
        });

        Schema::table('temple_receipts_80g', function (Blueprint $table) {
            $table->foreign('donation_id')->references('id')->on('temple_donations');

            $table->foreignUuid('seva_booking_id')
                ->nullable()
                ->after('donation_id')
                ->unique()
                ->constrained('temple_seva_bookings');

            // Which of the two sources issued this receipt. Stored rather
            // than derived from "which FK is null" so a reader (and the
            // Blade) has one obvious thing to branch on.
            $table->string('source_type', 20)->default('donation')->after('seva_booking_id');

            // Seva particulars, SNAPSHOT at issue time for the same reason
            // campaign_title is: renaming a seva or moving a booking must
            // never rewrite a statutory document already in a devotee's
            // hands.
            $table->string('seva_name', 255)->nullable()->after('donation_purpose');
            $table->date('seva_date')->nullable()->after('seva_name');
            $table->string('seva_slot_label', 100)->nullable()->after('seva_date');
            $table->string('seva_in_name_of', 255)->nullable()->after('seva_slot_label');
            $table->unsignedInteger('quantity')->nullable()->after('seva_in_name_of');
        });

        // Every row that exists today is a donation receipt.
        DB::table('temple_receipts_80g')->update(['source_type' => 'donation']);
    }

    public function down(): void
    {
        Schema::table('temple_receipts_80g', function (Blueprint $table) {
            $table->dropForeign(['seva_booking_id']);
            $table->dropUnique(['seva_booking_id']);
            $table->dropColumn([
                'seva_booking_id', 'source_type', 'seva_name',
                'seva_date', 'seva_slot_label', 'seva_in_name_of', 'quantity',
            ]);
        });
    }
};
