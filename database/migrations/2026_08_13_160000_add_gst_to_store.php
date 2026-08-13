<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST on store products (2026-08-13).
 *
 * Two deliberate differences from the hall implementation:
 *
 * 1. OPT-IN PER PRODUCT. `gst_enabled` defaults to false, so all 27 existing
 *    products — prasad, seva-linked items — stay untaxed until the trust
 *    ticks the box on the ones that genuinely carry GST. A trust-wide switch
 *    would have taxed the seva products, which is exactly what must not
 *    happen.
 *
 * 2. TAX IS PER LINE, NOT PER ORDER. One order can legitimately mix a taxed
 *    product with an untaxed one, and two taxed products can sit at
 *    different rates (food and non-food are not the same HSN). So the rate
 *    and amount snapshot onto temple_order_items; the order carries only the
 *    sums. An order-level gst_rate column would have to lie the moment a
 *    cart held two rates.
 *
 * GST is INCLUSIVE across the platform as of today: the price on the shelf
 * is the price paid, and tax is carved OUT of it for the invoice. So
 * `total_amount` and `subtotal` keep their exact existing meanings (the
 * gross amounts actually charged) and every payment path — Razorpay order
 * amount, Payment row, PaymentCaptureService, refunds, financial reports —
 * needs no change. The new columns only decompose what was already charged:
 *
 *     subtotal = taxable_amount + gst_amount        (per order)
 *     item.subtotal = item taxable + item.gst_amount (per line)
 *
 * Nullable rather than defaulted, for the same reason as halls: a NULL means
 * "this line was never taxed", while 0.00 would be the different and false
 * claim that we charged 0% tax on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_products', function (Blueprint $table): void {
            // Opt-in, product by product. Default false is load-bearing.
            $table->boolean('gst_enabled')->default(false)->after('is_seva_only');

            // Per-product override. NULL = use the trust-wide store rate in
            // System Settings, which is the normal case.
            $table->decimal('gst_rate', 5, 2)->nullable()->after('gst_enabled');
        });

        Schema::table('temple_orders', function (Blueprint $table): void {
            // Sums of the taxed lines. taxable_amount + gst_amount = the
            // taxed portion of `subtotal`; untaxed lines sit outside both.
            $table->decimal('taxable_amount', 10, 2)->nullable()->after('subtotal');
            $table->decimal('gst_amount', 10, 2)->nullable()->after('taxable_amount');
        });

        Schema::table('temple_order_items', function (Blueprint $table): void {
            // Snapshots, not live lookups: an order invoiced at 12% must
            // still print 12% after the trust changes the product to 18%.
            $table->decimal('gst_rate', 5, 2)->nullable()->after('subtotal');
            $table->decimal('gst_amount', 10, 2)->nullable()->after('gst_rate');
        });
    }

    public function down(): void
    {
        Schema::table('temple_order_items', function (Blueprint $table): void {
            $table->dropColumn(['gst_rate', 'gst_amount']);
        });

        Schema::table('temple_orders', function (Blueprint $table): void {
            $table->dropColumn(['taxable_amount', 'gst_amount']);
        });

        Schema::table('temple_products', function (Blueprint $table): void {
            $table->dropColumn(['gst_enabled', 'gst_rate']);
        });
    }
};
