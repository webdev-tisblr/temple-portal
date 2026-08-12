<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST on hall bookings (2026-08-12).
 *
 * Tax is added ON TOP of the hall's advertised day rate, not carved out of
 * it: the rate a devotee sees on /halls stays the rate, and the invoice
 * shows Taxable Value → CGST → SGST → Total.
 *
 * `total_amount` deliberately keeps its existing meaning — the GROSS amount
 * actually charged. Every payment path (Razorpay order amount, Payment row,
 * PaymentCaptureService, refunds, financial reports) reads that column, and
 * redefining it as the pre-tax figure would silently undercharge every
 * booking. The three new columns decompose it instead:
 *
 *     total_amount = subtotal_amount + gst_amount
 *
 * All three are nullable rather than defaulted, so historical bookings stay
 * honestly untaxed: a NULL gst_rate means "this booking predates GST", which
 * the invoice renders as a plain total exactly as it does today. A 0.00
 * would be a claim we charged 0% tax, which is a different statement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_halls', function (Blueprint $table): void {
            // Per-hall override. NULL = inherit the trust-wide default in
            // System Settings, which is the normal case.
            $table->decimal('gst_rate', 5, 2)->nullable()->after('price_per_half_day');
        });

        Schema::table('temple_hall_bookings', function (Blueprint $table): void {
            // Snapshots, not live lookups: a booking invoiced at 18% must
            // still print 18% after the trust changes the setting to 12%.
            $table->decimal('subtotal_amount', 10, 2)->nullable()->after('total_amount');
            $table->decimal('gst_rate', 5, 2)->nullable()->after('subtotal_amount');
            $table->decimal('gst_amount', 10, 2)->nullable()->after('gst_rate');
        });
    }

    public function down(): void
    {
        Schema::table('temple_hall_bookings', function (Blueprint $table): void {
            $table->dropColumn(['subtotal_amount', 'gst_rate', 'gst_amount']);
        });

        Schema::table('temple_halls', function (Blueprint $table): void {
            $table->dropColumn('gst_rate');
        });
    }
};
