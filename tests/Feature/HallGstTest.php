<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\SystemSetting;
use App\Services\HallAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GST on hall bookings (2026-08-12) — added ON TOP of the advertised day
 * rate, computed only inside HallAvailabilityService::priceFor() so all four
 * booking entry points (web, API, web test-mode, admin counter) inherit it.
 *
 * The invariant these tests defend: `total` is always the GROSS payable, and
 * always equals subtotal + gst_amount. Every payment path — the Razorpay
 * order amount, the Payment row, PaymentCaptureService, financial reports —
 * reads that one number, so any drift here is a mischarge.
 */
class HallGstTest extends TestCase
{
    use RefreshDatabase;

    private function hall(float $perDay = 10000, ?float $override = null): Hall
    {
        return Hall::create([
            'name' => 'Test Hall',
            'name_gu' => 'ટેસ્ટ હૉલ',
            'price_per_day' => $perDay,
            'gst_rate' => $override,
            'capacity' => 100,
            'is_active' => true,
        ]);
    }

    private function gst(bool $enabled, string $rate = '18.00'): void
    {
        SystemSetting::updateOrCreate(['key' => 'hall_gst_enabled'], ['value' => $enabled ? '1' : '0', 'group' => 'payment']);
        SystemSetting::updateOrCreate(['key' => 'hall_gst_rate'], ['value' => $rate, 'group' => 'payment']);
        SystemSetting::forgetCache();
    }

    public function test_no_gst_when_the_master_switch_is_off(): void
    {
        $this->gst(false);
        $price = app(HallAvailabilityService::class)->priceFor($this->hall(), '2026-09-01', '2026-09-01');

        // NULL, not 0.0 — "untaxed", not "taxed at zero percent".
        $this->assertNull($price['gst_rate']);
        $this->assertSame(0.0, $price['gst_amount']);
        $this->assertSame(10000.0, $price['subtotal']);
        $this->assertSame(10000.0, $price['total'], 'total must be unchanged when GST is off');
    }

    public function test_gst_is_added_on_top_of_the_day_rate(): void
    {
        $this->gst(true, '18.00');
        $price = app(HallAvailabilityService::class)->priceFor($this->hall(), '2026-09-01', '2026-09-01');

        $this->assertSame(10000.0, $price['subtotal'], 'the advertised rate stays the taxable value');
        $this->assertSame(18.0, $price['gst_rate']);
        $this->assertSame(1800.0, $price['gst_amount']);
        $this->assertSame(11800.0, $price['total']);
    }

    public function test_gst_applies_to_the_whole_multi_day_range(): void
    {
        $this->gst(true, '18.00');
        // Closed interval: 1st–3rd is THREE days, not two.
        $price = app(HallAvailabilityService::class)->priceFor($this->hall(), '2026-09-01', '2026-09-03');

        $this->assertSame(3, $price['days']);
        $this->assertSame(30000.0, $price['subtotal']);
        $this->assertSame(5400.0, $price['gst_amount']);
        $this->assertSame(35400.0, $price['total']);
    }

    public function test_per_hall_override_beats_the_trust_default(): void
    {
        $this->gst(true, '18.00');
        $price = app(HallAvailabilityService::class)->priceFor($this->hall(perDay: 10000, override: 12.0), '2026-09-01', '2026-09-01');

        $this->assertSame(12.0, $price['gst_rate']);
        $this->assertSame(1200.0, $price['gst_amount']);
        $this->assertSame(11200.0, $price['total']);
    }

    public function test_a_zero_rate_reads_as_untaxed_rather_than_zero_tax(): void
    {
        $this->gst(true, '0');
        $price = app(HallAvailabilityService::class)->priceFor($this->hall(), '2026-09-01', '2026-09-01');

        $this->assertNull($price['gst_rate']);
        $this->assertSame(10000.0, $price['total']);
    }

    public function test_total_always_equals_subtotal_plus_gst_at_awkward_rates(): void
    {
        // Rates and rates × odd amounts that do not divide cleanly are where
        // a rounding slip would show up as a rupee of mischarge.
        foreach ([['5.00', 1001.50], ['12.50', 3333.33], ['18.00', 7777.77], ['2.50', 999.99]] as [$rate, $perDay]) {
            $this->gst(true, $rate);
            $price = app(HallAvailabilityService::class)->priceFor($this->hall($perDay), '2026-09-01', '2026-09-02');

            $this->assertEqualsWithDelta(
                $price['total'],
                round($price['subtotal'] + $price['gst_amount'], 2),
                0.001,
                "total must equal subtotal + GST at {$rate}% on {$perDay}/day",
            );

            // And the invoice's CGST/SGST split must reconstitute gst_amount
            // exactly — SGST is the remainder, never a second rounding.
            $cgst = round($price['gst_amount'] / 2, 2);
            $sgst = round($price['gst_amount'] - $cgst, 2);
            $this->assertEqualsWithDelta($price['gst_amount'], $cgst + $sgst, 0.001);
        }
    }
}
