<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\SystemSetting;
use App\Services\HallAvailabilityService;
use Database\Factories\DevoteeFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GST on hall bookings — INCLUSIVE in the advertised day rate since
 * 2026-08-13 (it was added on top when introduced a day earlier). Computed
 * only inside HallAvailabilityService::priceFor() so all four booking entry
 * points (web, API, web test-mode, admin counter) inherit it.
 *
 * Two invariants these tests defend:
 *
 * 1. `total` never moves when GST is switched on. The devotee pays the
 *    advertised rate either way; the switch decides how much of it the trust
 *    keeps. Anything else is a price change dressed up as a tax setting.
 *
 * 2. `total` is always the GROSS payable and always equals
 *    subtotal + gst_amount. Every payment path — the Razorpay order amount,
 *    the Payment row, PaymentCaptureService, financial reports — reads that
 *    one number, so any drift here is a mischarge.
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

    public function test_gst_is_carved_out_of_the_day_rate_not_added_to_it(): void
    {
        $this->gst(true, '18.00');
        $price = app(HallAvailabilityService::class)->priceFor($this->hall(), '2026-09-01', '2026-09-01');

        // 10000 / 1.18 = 8474.576…, and the tax is the remainder.
        $this->assertSame(10000.0, $price['total'], 'the advertised rate IS what is charged');
        $this->assertSame(18.0, $price['gst_rate']);
        $this->assertSame(8474.58, $price['subtotal']);
        $this->assertSame(1525.42, $price['gst_amount']);
    }

    /**
     * The headline promise of inclusive pricing: flipping the setting must
     * not change anyone's bill. If this ever fails, the trust has silently
     * repriced every hall.
     */
    public function test_switching_gst_on_does_not_change_what_the_devotee_pays(): void
    {
        $service = app(HallAvailabilityService::class);
        $hall = $this->hall();

        $this->gst(false);
        $without = $service->priceFor($hall, '2026-09-01', '2026-09-03');

        $this->gst(true, '18.00');
        $with = $service->priceFor($hall, '2026-09-01', '2026-09-03');

        $this->assertSame($without['total'], $with['total']);
    }

    public function test_gst_applies_to_the_whole_multi_day_range(): void
    {
        $this->gst(true, '18.00');
        // Closed interval: 1st–3rd is THREE days, not two.
        $price = app(HallAvailabilityService::class)->priceFor($this->hall(), '2026-09-01', '2026-09-03');

        $this->assertSame(3, $price['days']);
        $this->assertSame(30000.0, $price['total'], '3 × the day rate, tax already inside');
        $this->assertSame(25423.73, $price['subtotal']);
        $this->assertSame(4576.27, $price['gst_amount']);
    }

    public function test_per_hall_override_beats_the_trust_default(): void
    {
        $this->gst(true, '18.00');
        $price = app(HallAvailabilityService::class)->priceFor($this->hall(perDay: 10000, override: 12.0), '2026-09-01', '2026-09-01');

        $this->assertSame(12.0, $price['gst_rate']);
        $this->assertSame(10000.0, $price['total']);
        $this->assertSame(8928.57, $price['subtotal']);
        $this->assertSame(1071.43, $price['gst_amount']);
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

    /**
     * The invoice is the document the hirer actually keeps, so the GST has to
     * survive all the way onto it — not just into the columns.
     */
    public function test_invoice_prints_the_full_gst_breakdown(): void
    {
        SystemSetting::updateOrCreate(['key' => 'trust_gstin'], ['value' => '24AAKTS1478C1ZP', 'group' => 'payment']);
        SystemSetting::forgetCache();

        $booking = $this->bookingWith(subtotal: 10000, rate: 18.0, gst: 1800, total: 11800);

        $html = view('invoices.hall-booking-invoice', $this->invoiceData($booking))->render();

        $this->assertStringContainsString('10,000.00', $html, 'taxable value');
        $this->assertStringContainsString('900.00', $html, 'CGST half');
        $this->assertStringContainsString('11,800.00', $html, 'gross total');
        $this->assertStringContainsString('24AAKTS1478C1ZP', $html, 'GSTIN must print');
        $this->assertStringContainsString('@ 9%', $html, 'rate shown per half, not 18% twice');
        $this->assertStringContainsString(__('receipt.label_taxable_value'), $html);
        $this->assertStringContainsString(__('receipt.label_cgst'), $html);
        $this->assertStringContainsString(__('receipt.label_sgst'), $html);
    }

    public function test_invoice_of_an_untaxed_booking_shows_no_gst_lines(): void
    {
        // Historical bookings predate GST; they must print exactly as before,
        // not sprout a "GST 0.00" row implying tax was charged.
        $booking = $this->bookingWith(subtotal: null, rate: null, gst: null, total: 10000);

        $html = view('invoices.hall-booking-invoice', $this->invoiceData($booking))->render();

        $this->assertStringNotContainsString(__('receipt.label_cgst'), $html);
        $this->assertStringNotContainsString('GSTIN', $html);
        $this->assertStringContainsString('10,000.00', $html);
    }

    /** Odd rate: CGST+SGST must still reconstitute gst_amount on the page. */
    public function test_invoice_halves_always_sum_back_to_the_gst_charged(): void
    {
        $booking = $this->bookingWith(subtotal: 1001.50, rate: 5.0, gst: 50.08, total: 1051.58);

        $html = view('invoices.hall-booking-invoice', $this->invoiceData($booking))->render();

        // 50.08 / 2 = 25.04 exactly; SGST is the remainder so it can never drift.
        $this->assertStringContainsString('25.04', $html);
        $this->assertStringContainsString('1,051.58', $html);
    }

    private function bookingWith(?float $subtotal, ?float $rate, ?float $gst, float $total): HallBooking
    {
        $hall = $this->hall();

        return HallBooking::create([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'hall_id' => $hall->id,
            'booking_date' => '2026-09-01',
            'end_date' => '2026-09-01',
            'days_count' => 1,
            'booking_type' => 'full_day',
            'purpose' => 'Wedding',
            'contact_name' => 'Ramesh',
            'contact_phone' => '9876543210',
            'total_amount' => $total,
            'subtotal_amount' => $subtotal,
            'gst_rate' => $rate,
            'gst_amount' => $gst,
            'status' => 'confirmed',
            'payment_id' => PaymentFactory::new()->create()->id,
        ]);
    }

    /** The same payload HallInvoiceService hands the blade. */
    private function invoiceData(HallBooking $booking): array
    {
        $booking->loadMissing('hall', 'devotee');

        return [
            'booking' => $booking,
            'trust_name' => 'Shree Patadiya Hanumanji Seva Trust',
            'trust_address' => 'Antarjal, Gandhidham',
            'trust_reg_no' => 'A/1497',
            'trust_80g_reg_no' => 'AAKTS1478C',
            'trust_pan' => 'AAKTS1478C',
            'booking_number' => 'HALL-1-20260901',
            'amount_in_words' => 'Eleven Thousand Eight Hundred Only',
            'status_label' => 'Confirmed',
            'booking_type_label' => 'Full Day',
            'trust_gstin' => SystemSetting::getValue('trust_gstin', ''),
        ];
    }
}
