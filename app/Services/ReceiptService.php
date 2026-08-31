<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Donation80GNotEligibleException;
use App\Helpers\NumberToWords;
use App\Models\Devotee;
use App\Models\Donation;
use App\Models\Receipt80G;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Support\Pdf\GujaratiPdf;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the statutory 80G receipt.
 *
 * Serves TWO sources since 2026-08-31: direct donations, and seva
 * bookings whose devotee ticked the 80G box at booking time. Both mint
 * from the SAME per-financial-year counter, so the trust files one
 * continuous statutory sequence (what Form 10BD expects) rather than two
 * interleaved ones. See generateForSevaBooking().
 *
 * DELIBERATELY ENGLISH-ONLY. Unlike the seva receipt / hall + store
 * invoices (localized 2026-08-09 via App\Support\DevoteeLocale), this is a
 * tax document that any assessing officer must be able to read, so its
 * labels are literals in the Blade and its trust name/address come from
 * the snapshotted receipt row. Do not wire the resources/lang receipt.php
 * translation files into receipts/receipt-80g.blade.php.
 *
 * Consequently the PDF path carries NO locale suffix — there is only ever
 * one rendering of a given receipt number.
 */
class ReceiptService
{
    /**
     * THE 80G GATE (item 5.4). Single definition of "may this donation be
     * issued a statutory 80G receipt", called from every one of the
     * donation sites that can mint a receipt number. The seva equivalent
     * is sevaIneligibilityReason() below; both share the PAN half.
     *
     * The rule is strict and amount-independent: a donation whose donor
     * has no readable, format-valid PAN on their profile NEVER gets an
     * 80G receipt and NEVER burns a receipt number. The ₹2,000 threshold
     * in PanValidationService::isPanRequired() is dead code and is
     * deliberately not revived.
     *
     * It has NOTHING to do with anonymity (corrected 2026-08-10). The
     * donation is still a named, ordinary donation on every public list;
     * it just carries no tax document. Equally, `anonymous` is never read
     * here — a Gupt Daan donor with a valid PAN gets their 80G receipt.
     *
     * Returns the reason code (a Donation80GNotEligibleException::REASON_*)
     * when the donation fails, or null when it passes.
     */
    public function ineligibilityReason(Donation $donation): ?string
    {
        // `!== null` guard on purpose: the column is NOT NULL with a `true`
        // default, so a null here means the attribute was never loaded onto
        // this instance (e.g. a model built before the column existed), NOT
        // that the donor declined. Reading "not loaded" as "declined" would
        // silently withhold receipts from donors who did ask for one.
        if ($donation->wants_80g !== null && ! $donation->wants_80g) {
            return Donation80GNotEligibleException::REASON_NOT_REQUESTED;
        }

        $donation->loadMissing('devotee');

        return $this->panIneligibilityReason($donation->devotee);
    }

    public function isEligibleFor80G(Donation $donation): bool
    {
        return $this->ineligibilityReason($donation) === null;
    }

    /**
     * THE 80G GATE, seva side. Same strict rule as donations, reading the
     * booking's own opt-in flag instead of the donation's.
     *
     * Deliberately shares panIneligibilityReason() with the donation path:
     * "what counts as a usable PAN" is a statutory question, and two
     * answers to it is how one surface starts issuing receipts the other
     * would refuse.
     *
     * Unlike donations, a seva booking's `wants_80g` defaults to FALSE, so
     * a booking made before this feature existed (or by an older app build
     * that does not send the flag) reads as "did not ask" and keeps its
     * ordinary localized seva receipt.
     */
    public function sevaIneligibilityReason(SevaBooking $booking): ?string
    {
        if (! $booking->wants_80g) {
            return Donation80GNotEligibleException::REASON_NOT_REQUESTED;
        }

        $booking->loadMissing('devotee');

        return $this->panIneligibilityReason($booking->devotee);
    }

    public function sevaBookingIsEligibleFor80G(SevaBooking $booking): bool
    {
        return $this->sevaIneligibilityReason($booking) === null;
    }

    /**
     * Does this devotee currently hold a PAN good enough for an 80G
     * receipt? Used by the checkout prompt (web + app) BEFORE a donation
     * row exists, so it takes a Devotee rather than a Donation.
     */
    public function devoteeHasValid80GPan(?Devotee $devotee): bool
    {
        return $this->panIneligibilityReason($devotee) === null;
    }

    private function panIneligibilityReason(?Devotee $devotee): ?string
    {
        if ($devotee === null || empty($devotee->pan_encrypted)) {
            return Donation80GNotEligibleException::REASON_NO_PAN;
        }

        $pan = $this->decryptPan($devotee);

        if ($pan === null) {
            return Donation80GNotEligibleException::REASON_PAN_UNREADABLE;
        }

        return app(PanValidationService::class)->validate($pan)
            ? null
            : Donation80GNotEligibleException::REASON_INVALID_PAN;
    }

    /**
     * Decrypt the stored PAN, or null when it cannot be read.
     *
     * The old code swallowed a decrypt failure and silently degraded to
     * '******{last4}' / 'N/A' — which, under a strict-PAN rule, would turn
     * an APP_KEY rotation into a compliance failure with no error anywhere.
     * A decrypt failure is now NOT eligible and is logged at `critical`.
     *
     * The PAN itself is returned to the caller and written only into the
     * receipt row. It is never logged, never put in an exception message,
     * and never returned over the API (clients get has_pan / last four).
     */
    private function decryptPan(Devotee $devotee): ?string
    {
        try {
            // decryptString(), NOT the decrypt() helper. Every write site
            // (web profile, complete-profile, PUT /me) stores the PAN with
            // Crypt::encryptString, i.e. WITHOUT PHP serialization — while
            // decrypt() unserializes by default and therefore blew up on
            // every real PAN. The old code swallowed that failure and
            // degraded to '******{last4}', which is why stored PANs were
            // printing masked on statutory receipts instead of in full.
            $pan = strtoupper(trim(Crypt::decryptString($devotee->pan_encrypted)));
        } catch (\Throwable $e) {
            Log::critical('80G: stored PAN could not be decrypted — donation treated as ineligible', [
                'devotee_id' => $devotee->id,
                'pan_last_four' => $devotee->pan_last_four,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $pan === '' ? null : $pan;
    }

    /**
     * Mint the next statutory receipt number for a financial year.
     *
     * The ONE place the "{prefix}/{fy}/{serial}" shape is assembled, so
     * donations and seva bookings cannot drift into two formats. MUST be
     * called inside a transaction — see allocateSerial().
     */
    private function mintReceiptNumber(string $financialYear): string
    {
        $prefix = SystemSetting::getValue('receipt_prefix', 'SPHST/80G');
        $serial = str_pad((string) $this->allocateSerial($financialYear), 5, '0', STR_PAD_LEFT);

        return "{$prefix}/{$financialYear}/{$serial}";
    }

    /**
     * Trust-side snapshot columns, identical for both sources.
     *
     * @return array<string,mixed>
     */
    private function trustSnapshot(): array
    {
        return [
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            'trust_address' => SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205'),
            'trust_pan' => SystemSetting::getValue('trust_pan', ''),
            'trust_80g_registration_no' => SystemSetting::getValue('trust_80g_reg_no', ''),
            'trust_80g_validity_period' => SystemSetting::getValue('trust_80g_validity', ''),
        ];
    }

    /**
     * Devotee-side snapshot columns, identical for both sources.
     *
     * @return array<string,mixed>
     */
    private function devoteeSnapshot(Devotee $devotee, string $panNumber): array
    {
        return [
            'devotee_name' => $devotee->name ?: 'Devotee',
            'devotee_address' => collect([$devotee->address, $devotee->city, $devotee->state, $devotee->pincode])
                ->filter()->implode(', '),
            'devotee_phone' => $devotee->phone,
            'devotee_email' => $devotee->email,
            'pan_number' => $panNumber,
        ];
    }

    /**
     * @throws Donation80GNotEligibleException when the strict PAN rule
     *                                         withholds the receipt
     */
    public function generateReceipt(Donation $donation): Receipt80G
    {
        $existing = Receipt80G::where('donation_id', $donation->id)->first();
        if ($existing) {
            // Already-issued receipt: this is a PDF cache refresh, NOT an
            // allocation, so the strict rule deliberately does not apply.
            // Gating here instead of at creation would silently withdraw
            // receipts that are already in donors' hands.
            return $this->ensurePdf($existing);
        }

        $donation->loadMissing('devotee', 'payment', 'campaign');

        $reason = $this->ineligibilityReason($donation);
        if ($reason !== null) {
            $this->recordIneligible($donation, $reason);

            throw Donation80GNotEligibleException::for($donation, $reason);
        }

        $devotee = $donation->devotee;
        // Non-null by construction: ineligibilityReason() just proved the
        // PAN decrypts and matches the statutory format.
        $panNumber = (string) $this->decryptPan($devotee);

        // Allocation and row creation share ONE transaction so a failed
        // create cannot leave the counter ahead of reality, and the
        // counter row lock serialises concurrent captures (defect A).
        $receipt = DB::transaction(function () use ($donation, $devotee, $panNumber) {
            $fy = $donation->financial_year;

            return Receipt80G::create([
                'donation_id' => $donation->id,
                'source_type' => Receipt80G::SOURCE_DONATION,
                'receipt_number' => $this->mintReceiptNumber($fy),
                'financial_year' => $fy,
                // Snapshots (defect B / item 5.2) — frozen at issue time so
                // renaming a campaign or editing a purpose later cannot
                // rewrite a receipt that has already been issued.
                'campaign_title' => $this->liveCampaignTitle($donation),
                'amount' => $donation->amount,
                'amount_in_words' => NumberToWords::convert((float) $donation->amount),
                'donation_date' => $donation->created_at->toDateString(),
                'payment_mode' => $donation->payment?->method ?? 'Online',
                'generated_at' => now(),
            ] + $this->devoteeSnapshot($devotee, $panNumber) + $this->trustSnapshot());
        });

        // The donation's own verdict column now agrees with reality.
        if (! $donation->is_80g_eligible) {
            $donation->update(['is_80g_eligible' => true]);
        }

        $pdfPath = $this->generatePdf($receipt);
        $receipt->update(['pdf_path' => $pdfPath]);

        return $receipt;
    }

    /**
     * ALLOCATION SITE #5 — the statutory 80G receipt for a SEVA BOOKING.
     *
     * Mirrors generateReceipt(Donation) exactly, and deliberately reuses
     * its gate, its counter and its snapshot helpers. The one structural
     * difference is what gets snapshotted: a seva has particulars
     * (which seva, which date, which slot, in whose name) where a donation
     * has a campaign, so those go into their own frozen columns and the
     * Blade branches on `source_type`.
     *
     * The caller is GenerateSevaReceipt, which treats the throw as
     * "fall back to the ordinary seva receipt" rather than as a failure —
     * a PAID booking must never end up with no receipt at all.
     *
     * @throws Donation80GNotEligibleException when the strict PAN rule
     *                                         withholds the receipt
     */
    public function generateForSevaBooking(SevaBooking $booking): Receipt80G
    {
        $existing = Receipt80G::where('seva_booking_id', $booking->id)->first();
        if ($existing) {
            // Already-issued receipt: a PDF cache refresh, NOT an
            // allocation, so the strict rule deliberately does not apply.
            // Withdrawing a receipt already in a devotee's hands because
            // they later cleared their PAN would be worse than keeping it.
            return $this->ensurePdf($existing);
        }

        $booking->loadMissing('devotee', 'seva', 'payment', 'selectedProduct');

        $reason = $this->sevaIneligibilityReason($booking);
        if ($reason !== null) {
            $this->recordSevaIneligible($booking, $reason);

            throw Donation80GNotEligibleException::forSevaBooking($booking, $reason);
        }

        $devotee = $booking->devotee;
        // Non-null by construction: sevaIneligibilityReason() just proved
        // the PAN decrypts and matches the statutory format.
        $panNumber = (string) $this->decryptPan($devotee);

        $receipt = DB::transaction(function () use ($booking, $devotee, $panNumber) {
            $fy = $booking->financial_year;

            return Receipt80G::create([
                'seva_booking_id' => $booking->id,
                'source_type' => Receipt80G::SOURCE_SEVA,
                'receipt_number' => $this->mintReceiptNumber($fy),
                'financial_year' => $fy,
                // Seva particulars, frozen at issue time for the same
                // reason campaign_title is: renaming a seva must never
                // rewrite a statutory document already issued.
                'seva_name' => $this->sevaTitleFor($booking),
                'seva_date' => $booking->booking_date?->toDateString(),
                'seva_slot_label' => $booking->slot_time_label,
                'seva_in_name_of' => $booking->devotee_name_for_seva,
                'quantity' => $booking->quantity,
                'amount' => $booking->total_amount,
                'amount_in_words' => NumberToWords::convert((float) $booking->total_amount),
                // The date the MONEY moved, matching the donation path.
                // The seva itself may fall in a later year; the deduction
                // is claimed for the year it was paid in.
                'donation_date' => $booking->created_at->toDateString(),
                'payment_mode' => $booking->payment?->method ?? 'Online',
                'generated_at' => now(),
            ] + $this->devoteeSnapshot($devotee, $panNumber) + $this->trustSnapshot());
        });

        if (! $booking->is_80g_eligible) {
            $booking->update(['is_80g_eligible' => true]);
        }

        $receipt->update(['pdf_path' => $this->generatePdf($receipt)]);

        return $receipt;
    }

    /**
     * ENGLISH seva name on purpose — the 80G receipt is English-only, so
     * prefer `name_en` and fall back to Gujarati only when the admin left
     * the English column blank. Same rule as liveCampaignTitle().
     */
    private function sevaTitleFor(SevaBooking $booking): ?string
    {
        $seva = $booking->seva;

        if (! $seva) {
            return null;
        }

        $title = trim((string) ($seva->name_en ?: $seva->name_gu));

        return $title !== '' ? $title : null;
    }

    /**
     * Persist the verdict for a seva booking that will never get an 80G
     * receipt. Touches `is_80g_eligible` and NOTHING ELSE — `wants_80g`
     * records what the devotee asked for and must survive the refusal, or
     * the admin loses the ability to see who asked and was turned down for
     * want of a PAN.
     */
    private function recordSevaIneligible(SevaBooking $booking, string $reason): void
    {
        if ($booking->is_80g_eligible) {
            $booking->update(['is_80g_eligible' => false]);
        }

        Log::info('Seva 80G receipt withheld — strict PAN rule', [
            'booking_id' => $booking->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Refresh the cached PDF of an ALREADY-ISSUED receipt. Allocation-free
     * by construction — it takes a Receipt80G, so there is no path from
     * here to a new receipt number. This is what the web dashboard
     * download uses (allocation site #4, which must stay PDF-only).
     */
    public function ensurePdf(Receipt80G $receipt): Receipt80G
    {
        if (! $receipt->pdf_path || ! Storage::disk('r2_private')->exists($receipt->pdf_path)) {
            $receipt->update(['pdf_path' => $this->generatePdf($receipt)]);
        }

        return $receipt;
    }

    /**
     * Take the next serial for a financial year under a row lock.
     *
     * Only ever moves FORWARD. Deleting a receipt row does not rewind the
     * counter, so a burnt number can never be handed out twice; a rolled
     * back generation simply leaves a gap, which is acceptable and far
     * safer than reuse.
     *
     * The insert-then-relock dance covers the first receipt of a new
     * financial year: two racing captures may both find no counter row,
     * both attempt the insert (one is ignored on the primary key), and
     * then both serialise on the lockForUpdate() that follows.
     *
     * PUBLIC only so the concurrency test can hammer it from genuinely
     * parallel PHP processes (an in-process loop would prove nothing
     * about row locking). Every production caller reaches it through
     * mintReceiptNumber(), from generateReceipt() or
     * generateForSevaBooking() — do NOT call it directly, or you will burn
     * a statutory receipt number with no receipt attached to it.
     *
     * Shared by both sources on purpose: donations and seva bookings
     * interleave in ONE per-year sequence, which is what the trust files.
     *
     * MUST be called inside a transaction: lockForUpdate() outside one
     * is released immediately and buys nothing.
     */
    public function allocateSerial(string $financialYear): int
    {
        $row = DB::table('temple_receipt_sequences')
            ->where('financial_year', $financialYear)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            DB::table('temple_receipt_sequences')->insertOrIgnore([
                'financial_year' => $financialYear,
                // Never start below what is already issued — protects an
                // install whose counter row was lost or truncated.
                'last_serial' => $this->highestIssuedSerial($financialYear),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('temple_receipt_sequences')
                ->where('financial_year', $financialYear)
                ->lockForUpdate()
                ->first();
        }

        $next = (int) $row->last_serial + 1;

        DB::table('temple_receipt_sequences')
            ->where('financial_year', $financialYear)
            ->update(['last_serial' => $next, 'updated_at' => now()]);

        return $next;
    }

    private function highestIssuedSerial(string $financialYear): int
    {
        $max = DB::table('temple_receipts_80g')
            ->where('financial_year', $financialYear)
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(receipt_number, '/', -1) AS UNSIGNED)) AS max_serial")
            ->value('max_serial');

        return (int) $max;
    }

    /**
     * Persist the verdict for a donation that will never get a receipt.
     *
     * ⚠ This touches `is_80g_eligible` and NOTHING ELSE (corrected
     * 2026-08-10 after live testing). It used to also flip `anonymous` to
     * true whenever the PAN was missing, which silently turned ordinary
     * named donors into Gupt Daan donors and erased them from the public
     * donor lists. The two concerns are independent:
     *
     *   no valid PAN  → no receipt, no number burnt. Donor stays NAMED.
     *   Gupt Daan tick → name masked on public lists. Receipt unaffected —
     *                    a Gupt Daan donor with a PAN still gets their 80G.
     *
     * `anonymous` is owned solely by the donor's checkbox at checkout (and
     * by AccountController's deletion path). Nothing in the receipt
     * pipeline may write it.
     */
    private function recordIneligible(Donation $donation, string $reason): void
    {
        $updates = [];

        if ($donation->is_80g_eligible) {
            $updates['is_80g_eligible'] = false;
        }

        if ($updates !== []) {
            $donation->update($updates);
        }

        Log::info('80G receipt withheld — strict PAN rule', [
            'donation_id' => $donation->id,
            'reason' => $reason,
        ]);
    }

    public function generatePdf(Receipt80G $receipt): string
    {
        // mPDF via GujaratiPdf, NOT DomPDF: donor names/addresses carry
        // Gujarati, and DomPDF cannot shape Indic text — matras and
        // conjuncts render in the wrong visual order (user-visible on
        // real 80G receipts, 2026-07-26). Same migration the store
        // invoice + packing slip went through.
        $output = GujaratiPdf::render('receipts.receipt-80g', [
            'receipt' => $receipt,
            'campaign_title' => $this->campaignTitleFor($receipt),
        ], [
            'format' => 'A4',
            // The watermark names what the document IS, so a seva 80G
            // receipt must not read "80G RECEIPT" over seva particulars.
            'watermark' => $receipt->isForSeva() ? 'SEVA 80G RECEIPT' : '80G RECEIPT',
        ]);

        $directory = "receipts/{$receipt->financial_year}";
        $filename = str_replace('/', '-', $receipt->receipt_number).'.pdf';
        $path = "{$directory}/{$filename}";

        // R2 has no concept of directories — put() writes the key directly.
        Storage::disk('r2_private')->put($path, $output);

        return $path;
    }

    /**
     * Campaign name printed on the receipt when the donation was made
     * towards a campaign (item 5.2).
     *
     * Reads the SNAPSHOT taken at issue time (defect B). It used to be
     * resolved live through `$receipt->donation->campaign`, which meant an
     * admin renaming a campaign silently rewrote the text of receipts that
     * were already in donors' hands — the PDF is a regenerable cache, so
     * the change lands on the very next re-render.
     *
     * The live relation survives only as a fallback for rows issued before
     * the snapshot column existed, where the live value is the sole value
     * available.
     *
     * ENGLISH title on purpose — the 80G receipt is English-only, so
     * prefer `title_en` and only fall back to Gujarati when the admin
     * left the English column blank.
     *
     * Public so a test can assert what an issued receipt WILL print
     * without having to parse compressed mPDF output.
     */
    public function campaignTitleFor(Receipt80G $receipt): ?string
    {
        // A seva receipt has no campaign and no donation to fall back
        // through — return early rather than dereferencing a null relation.
        if ($receipt->isForSeva()) {
            return null;
        }

        $snapshot = trim((string) $receipt->campaign_title);

        if ($snapshot !== '') {
            return $snapshot;
        }

        return $this->liveCampaignTitle($receipt->donation);
    }

    private function liveCampaignTitle(?Donation $donation): ?string
    {
        $campaign = $donation?->campaign;

        if (! $campaign) {
            return null;
        }

        $title = trim((string) ($campaign->title_en ?: $campaign->title_gu));

        return $title !== '' ? $title : null;
    }
}
