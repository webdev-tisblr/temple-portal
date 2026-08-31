<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\Donation80GNotEligibleException;
use App\Jobs\GenerateSevaReceipt;
use App\Models\Donation;
use App\Models\Receipt80G;
use App\Models\SevaBooking;
use App\Services\Notifications\NotificationService;
use App\Services\ReceiptService;
use App\Services\SevaReceiptService;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Seva bookings can carry a statutory 80G receipt (2026-08-31).
 *
 * This reverses a deliberate 2026-05-13 decision, so the coverage here is
 * about the things that decision was protecting:
 *
 *   1. The statutory SEQUENCE stays single and forward-only. A seva
 *      receipt and a donation receipt interleave in one per-FY series,
 *      because that is what the trust files (Form 10BD).
 *   2. The strict PAN rule still holds. Asked-for-but-refused burns NO
 *      number — and, unlike a donation, still produces the ordinary seva
 *      receipt, because the devotee has already paid.
 *   3. No synthetic Donation row. That synthesis is what double-counted
 *      seva payments in the donation totals and got removed in the first
 *      place.
 *
 * MySQL only.
 */
class Seva80GReceiptTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ReceiptService
    {
        return app(ReceiptService::class);
    }

    /** @param array<string,mixed> $attributes */
    private function booking(bool $withPan, array $attributes = []): SevaBooking
    {
        $devotee = $withPan
            ? DevoteeFactory::new()->withPan()->create()
            : DevoteeFactory::new()->create();

        return SevaBookingFactory::new()->create(array_merge([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'status' => 'confirmed',
            'total_amount' => 2500,
        ], $attributes));
    }

    private function sequenceFor(string $fy): int
    {
        return (int) DB::table('temple_receipt_sequences')
            ->where('financial_year', $fy)
            ->value('last_serial');
    }

    // ── The happy path ───────────────────────────────────────────────

    public function test_a_booking_that_opted_in_with_a_valid_pan_gets_a_statutory_receipt(): void
    {
        $booking = $this->booking(true, ['wants_80g' => true]);

        $receipt = $this->service()->generateForSevaBooking($booking);

        $this->assertSame(Receipt80G::SOURCE_SEVA, $receipt->source_type);
        $this->assertSame($booking->id, $receipt->seva_booking_id);
        $this->assertNull($receipt->donation_id, 'a seva receipt must not claim a donation');
        $this->assertStringContainsString('/80G/', $receipt->receipt_number);
        $this->assertSame('2500.00', $receipt->amount);
        $this->assertNotNull($receipt->pdf_path);
        Storage::disk('r2_private')->assertExists($receipt->pdf_path);

        // The verdict is recorded on the booking, not just implied.
        $this->assertTrue($booking->fresh()->is_80g_eligible);
    }

    public function test_no_synthetic_donation_row_is_created(): void
    {
        // The 2026-05-13 regression, asserted directly: synthesising a
        // Donation here double-counts the payment in every donation total,
        // dashboard and financial report.
        $booking = $this->booking(true, ['wants_80g' => true]);

        $this->service()->generateForSevaBooking($booking);

        $this->assertDatabaseCount('temple_donations', 0);
    }

    public function test_seva_80g_money_never_enters_the_donation_accounts(): void
    {
        // The trust's explicit requirement (confirmed 2026-08-31): a seva
        // may issue an 80G receipt, but seva income and donation income stay
        // SEPARATE in the books. Issuing the receipt must not move a rupee
        // into the donation side of the accounts.
        //
        // This is the 2026-05-13 regression stated as an accounting fact
        // rather than a schema one: the old synthetic Donation row made
        // every 80G seva booking show up as a donation too, so a ₹2,500
        // abhishek read as ₹5,000 of income across the dashboards.
        $booking = $this->booking(true, ['wants_80g' => true, 'total_amount' => 2500]);

        $this->service()->generateForSevaBooking($booking);

        // Nothing on the donation side moved at all.
        $this->assertDatabaseCount('temple_donations', 0);
        $this->assertSame(0.0, (float) Donation::sum('amount'));

        // And the receipt is reachable ONLY from the seva side, so a report
        // that walks Donation->receipt (as FinancialReports does) can never
        // pick it up.
        $this->assertSame(1, Receipt80G::whereNotNull('seva_booking_id')->count());
        $this->assertSame(0, Receipt80G::whereNotNull('donation_id')->count());
    }

    public function test_the_seva_particulars_are_snapshotted_not_resolved_live(): void
    {
        $booking = $this->booking(true, [
            'wants_80g' => true,
            'devotee_name_for_seva' => 'Ramesh Patel',
            'quantity' => 2,
        ]);
        $booking->seva->update(['name_en' => 'Abhishek', 'name_gu' => 'અભિષેક']);

        $receipt = $this->service()->generateForSevaBooking($booking->fresh());

        $this->assertSame('Abhishek', $receipt->seva_name);
        $this->assertSame('Ramesh Patel', $receipt->seva_in_name_of);
        $this->assertSame(2, $receipt->quantity);

        // Renaming the seva must NOT rewrite a receipt already issued.
        $booking->seva->update(['name_en' => 'Maha Abhishek']);
        $this->assertSame('Abhishek', $receipt->fresh()->seva_name);
    }

    // ── The sequence is shared and forward-only ──────────────────────

    public function test_seva_and_donation_receipts_share_one_financial_year_series(): void
    {
        $donation = DonationFactory::new()->create([
            'devotee_id' => DevoteeFactory::new()->withPan()->create()->id,
            'payment_id' => PaymentFactory::new()->create()->id,
        ]);
        $donationReceipt = $this->service()->generateReceipt($donation);

        $booking = $this->booking(true, ['wants_80g' => true]);
        $sevaReceipt = $this->service()->generateForSevaBooking($booking);

        $this->assertSame(
            $donation->financial_year,
            $sevaReceipt->financial_year,
            'both sources must be stamped with the same FY string, or they key two counters',
        );

        // Consecutive serials out of ONE counter — the point of the whole
        // shared-sequence decision.
        $serial = fn (Receipt80G $r) => (int) substr(strrchr($r->receipt_number, '/'), 1);
        $this->assertSame($serial($donationReceipt) + 1, $serial($sevaReceipt));
        $this->assertSame(2, $this->sequenceFor($donation->financial_year));
    }

    public function test_a_second_call_returns_the_same_receipt_without_burning_a_number(): void
    {
        $booking = $this->booking(true, ['wants_80g' => true]);

        $first = $this->service()->generateForSevaBooking($booking);
        $burnt = $this->sequenceFor($first->financial_year);

        $second = $this->service()->generateForSevaBooking($booking->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->receipt_number, $second->receipt_number);
        $this->assertSame($burnt, $this->sequenceFor($first->financial_year));
        $this->assertSame(1, Receipt80G::count());
    }

    // ── The strict PAN rule ──────────────────────────────────────────

    public function test_opted_in_without_a_pan_gets_no_receipt_and_burns_no_number(): void
    {
        $booking = $this->booking(false, ['wants_80g' => true]);

        try {
            $this->service()->generateForSevaBooking($booking);
            $this->fail('a PAN-less booking must not be issued an 80G receipt');
        } catch (Donation80GNotEligibleException $e) {
            $this->assertSame(Donation80GNotEligibleException::REASON_NO_PAN, $e->reason);
            $this->assertSame(Donation80GNotEligibleException::SUBJECT_SEVA_BOOKING, $e->subjectType);
        }

        $this->assertSame(0, Receipt80G::count());
        // No counter row at all — stronger than "the counter did not move".
        $this->assertDatabaseMissing('temple_receipt_sequences', [
            'financial_year' => $booking->financial_year,
        ]);
    }

    public function test_a_refusal_keeps_wants_80g_so_the_trust_can_see_who_asked(): void
    {
        $booking = $this->booking(false, ['wants_80g' => true]);

        try {
            $this->service()->generateForSevaBooking($booking);
        } catch (Donation80GNotEligibleException) {
            // expected
        }

        $fresh = $booking->fresh();
        $this->assertTrue($fresh->wants_80g, 'the request must survive the refusal');
        $this->assertFalse($fresh->is_80g_eligible, 'the verdict must be recorded');
    }

    public function test_a_booking_that_did_not_opt_in_is_refused_as_not_requested(): void
    {
        // Every booking made before this feature existed, and every one from
        // an older app build that does not send the flag.
        $booking = $this->booking(true, ['wants_80g' => false]);

        try {
            $this->service()->generateForSevaBooking($booking);
            $this->fail('a booking that did not ask must not be issued an 80G receipt');
        } catch (Donation80GNotEligibleException $e) {
            $this->assertSame(Donation80GNotEligibleException::REASON_NOT_REQUESTED, $e->reason);
        }

        $this->assertSame(0, Receipt80G::count());
    }

    // ── The job picks the right document ─────────────────────────────

    public function test_the_job_issues_the_80g_receipt_and_no_plain_one(): void
    {
        $booking = $this->booking(true, ['wants_80g' => true]);

        (new GenerateSevaReceipt($booking, sendNotification: false))->handle(
            app(SevaReceiptService::class),
            app(NotificationService::class),
            app(ReceiptService::class),
        );

        $this->assertSame(1, Receipt80G::count());
        // ONE receipt per booking: the plain path must not have run, or the
        // booking would carry a SEVA-… number and a second PDF.
        $this->assertNull($booking->fresh()->receipt_number);
        $this->assertNull($booking->fresh()->receipt_path);
    }

    public function test_the_job_falls_back_to_the_plain_receipt_when_the_pan_is_missing(): void
    {
        // A PAID booking must never end up with no receipt at all.
        $booking = $this->booking(false, ['wants_80g' => true]);

        (new GenerateSevaReceipt($booking, sendNotification: false))->handle(
            app(SevaReceiptService::class),
            app(NotificationService::class),
            app(ReceiptService::class),
        );

        $this->assertSame(0, Receipt80G::count(), 'no statutory receipt');
        $this->assertDatabaseMissing('temple_receipt_sequences', [
            'financial_year' => $booking->financial_year,
        ]);

        $fresh = $booking->fresh();
        $this->assertNotNull($fresh->receipt_number, 'the ordinary seva receipt must still be issued');
        $this->assertStringStartsWith('SEVA-', $fresh->receipt_number);
        Storage::disk('r2_private')->assertExists($fresh->receipt_path);
    }

    public function test_a_booking_that_did_not_opt_in_keeps_todays_behaviour_exactly(): void
    {
        $booking = $this->booking(true, ['wants_80g' => false]);

        (new GenerateSevaReceipt($booking, sendNotification: false))->handle(
            app(SevaReceiptService::class),
            app(NotificationService::class),
            app(ReceiptService::class),
        );

        $this->assertSame(0, Receipt80G::count());
        $this->assertStringStartsWith('SEVA-', (string) $booking->fresh()->receipt_number);
    }

    // ── The number spaces stay separate ──────────────────────────────

    public function test_a_statutory_number_never_lands_in_the_bookings_own_column(): void
    {
        // SevaReceiptService::pathFor() derives the storage key from
        // booking.receipt_number, so a statutory number there would file a
        // plain PDF under a statutory name.
        $booking = $this->booking(true, ['wants_80g' => true]);

        $receipt = $this->service()->generateForSevaBooking($booking);

        $this->assertNotSame($receipt->receipt_number, $booking->fresh()->receipt_number);
        $this->assertNull($booking->fresh()->receipt_number);
    }

    public function test_booking_reference_prefers_the_statutory_number_when_loaded(): void
    {
        $booking = $this->booking(true, ['wants_80g' => true]);
        $receipt = $this->service()->generateForSevaBooking($booking);

        $loaded = SevaBooking::with('receipt80G')->find($booking->id);

        $this->assertSame($receipt->receipt_number, $loaded->booking_reference);
        $this->assertSame($receipt->receipt_number, $loaded->display_receipt_number);
    }
}
