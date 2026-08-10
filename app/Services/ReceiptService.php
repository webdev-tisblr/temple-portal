<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\Donation80GNotEligibleException;
use App\Helpers\NumberToWords;
use App\Models\Devotee;
use App\Models\Donation;
use App\Models\Receipt80G;
use App\Models\SystemSetting;
use App\Support\Pdf\GujaratiPdf;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the statutory 80G donation receipt.
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
     * issued a statutory 80G receipt", called from every one of the four
     * sites that can mint a receipt number.
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
            $prefix = SystemSetting::getValue('receipt_prefix', 'SPHST/80G');
            $fy = $donation->financial_year;
            $serial = str_pad((string) $this->allocateSerial($fy), 5, '0', STR_PAD_LEFT);

            return Receipt80G::create([
                'donation_id' => $donation->id,
                'receipt_number' => "{$prefix}/{$fy}/{$serial}",
                'financial_year' => $fy,
                // Snapshots (defect B / item 5.2) — frozen at issue time so
                // renaming a campaign or editing a purpose later cannot
                // rewrite a receipt that has already been issued.
                'campaign_title' => $this->liveCampaignTitle($donation),
                'donation_purpose' => $donation->purpose ?: null,
                'devotee_name' => $devotee->name ?: 'Devotee',
                'devotee_address' => collect([$devotee->address, $devotee->city, $devotee->state, $devotee->pincode])
                    ->filter()->implode(', '),
                'devotee_phone' => $devotee->phone,
                'devotee_email' => $devotee->email,
                'pan_number' => $panNumber,
                'amount' => $donation->amount,
                'amount_in_words' => NumberToWords::convert((float) $donation->amount),
                'donation_date' => $donation->created_at->toDateString(),
                'payment_mode' => $donation->payment?->method ?? 'Online',
                'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
                'trust_address' => SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205'),
                'trust_pan' => SystemSetting::getValue('trust_pan', ''),
                'trust_80g_registration_no' => SystemSetting::getValue('trust_80g_reg_no', ''),
                'trust_80g_validity_period' => SystemSetting::getValue('trust_80g_validity', ''),
                'generated_at' => now(),
            ]);
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
     * generateReceipt() — do NOT call it directly, or you will burn a
     * statutory receipt number with no receipt attached to it.
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
        ], ['format' => 'A4', 'watermark' => '80G RECEIPT']);

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
