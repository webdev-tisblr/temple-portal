<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\SystemSetting;

/**
 * THE one place store GST is calculated.
 *
 * Store checkout exists in four places — the API (mobile app), the web
 * checkout, the web test-mode path, and the admin counter entry — each with
 * its own line-building loop. Tax computed in four loops is tax that will
 * eventually disagree with itself, and a mismatch here means the amount
 * charged through Razorpay and the amount printed on the invoice are
 * different numbers. So every one of them decomposes its lines through
 * decompose() and nothing else does the arithmetic.
 *
 * GST IS INCLUSIVE. The price on the product IS the price the devotee pays;
 * tax is carved out of it for the invoice. That direction matters for
 * rounding: the gross figure is authoritative and the taxable value is
 * derived from it, never the other way round, or the amount charged would
 * drift a paisa away from the advertised price.
 */
class StoreGstService
{
    /**
     * Effective GST rate for one product, or NULL when it is untaxed.
     *
     * Order: the trust-wide master switch, then the product's own opt-in,
     * then its rate override, then the trust-wide rate. A product is taxed
     * only if it says so — that is the whole point of the per-product flag,
     * and it is what keeps prasad and seva-linked items out of the tax net.
     */
    public function rateFor(Product $product): ?float
    {
        if (SystemSetting::getValue('store_gst_enabled', '0') !== '1') {
            return null;
        }

        if (! $product->gst_enabled) {
            return null;
        }

        $rate = $product->gst_rate !== null
            ? (float) $product->gst_rate
            : (float) SystemSetting::getValue('store_gst_rate', '0');

        return $rate > 0 ? round($rate, 2) : null;
    }

    /**
     * Carve the tax out of a GROSS line amount.
     *
     * @return array{taxable: float, gst: float}
     */
    public function split(float $gross, ?float $rate): array
    {
        if ($rate === null || $rate <= 0.0) {
            return ['taxable' => round($gross, 2), 'gst' => 0.0];
        }

        $taxable = round($gross / (1 + $rate / 100), 2);

        // The tax is the REMAINDER, never a second rounded computation:
        // taxable + gst must equal the gross to the paisa, or the invoice
        // does not add up against the amount actually charged.
        return ['taxable' => $taxable, 'gst' => round($gross - $taxable, 2)];
    }

    /**
     * Decompose priced order lines.
     *
     * Each input line needs at least `product` (or `product_id`) and
     * `subtotal` (the GROSS line amount). Returns the same lines with
     * `gst_rate` + `gst_amount` added, plus the order-level sums.
     *
     * `subtotal` is untouched and keeps meaning "what this line costs" —
     * inclusive pricing means adding tax must never change a total.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array{lines: list<array<string, mixed>>, subtotal: float, taxable_amount: ?float, gst_amount: ?float}
     */
    public function decompose(array $lines): array
    {
        $out = [];
        $subtotal = 0.0;
        $taxable = 0.0;
        $gst = 0.0;
        $anyTaxed = false;

        foreach ($lines as $line) {
            $product = $line['product'] ?? null;
            if (! $product instanceof Product && ! empty($line['product_id'])) {
                $product = Product::find($line['product_id']);
            }

            $gross = round((float) ($line['subtotal'] ?? 0), 2);
            $subtotal += $gross;

            $rate = $product instanceof Product ? $this->rateFor($product) : null;
            $parts = $this->split($gross, $rate);

            if ($rate !== null) {
                $anyTaxed = true;
                $taxable += $parts['taxable'];
                $gst += $parts['gst'];
            }

            $line['gst_rate'] = $rate;
            $line['gst_amount'] = $rate === null ? null : $parts['gst'];
            $out[] = $line;
        }

        return [
            'lines' => $out,
            'subtotal' => round($subtotal, 2),
            // NULL, not 0.00, when nothing in the order was taxable — the
            // invoice reads that as "no GST applies" and prints a plain
            // total, rather than claiming we charged 0% tax.
            'taxable_amount' => $anyTaxed ? round($taxable, 2) : null,
            'gst_amount' => $anyTaxed ? round($gst, 2) : null,
        ];
    }
}
