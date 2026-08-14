<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\Donation80GNotEligibleException;
use App\Models\Donation;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use App\Services\ReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the 80G receipt PDF for a captured donation and hands it to
 * NotificationService::dispatch with the presigned URL exposed as a
 * placeholder and the PDF bytes exposed as an email attachment.
 *
 * The greeting card is a SEPARATE deliverable — see GenerateGreetingCard,
 * dispatched independently from PaymentCaptureService under its own
 * `donation.greeting_card` trigger. This job no longer touches it.
 *
 * NOTHING is sent from inside this job. Channels fire only if the admin
 * has created and enabled a NotificationTemplate row for the
 * `donation.receipt_80g` trigger on that channel (email / whatsapp /
 * sms / push). That's the rule for the whole platform — no hardcoded
 * sends, every message originates from a template row.
 */
class Generate80GReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Donation $donation,
    ) {}

    public function handle(ReceiptService $receiptService): void
    {
        // ALLOCATION SITE #1 of 4 (item 5.4). Strict rule: no readable,
        // format-valid PAN on the donor profile → no Receipt80G row and no
        // receipt number burned, regardless of amount. generateReceipt()
        // throws rather than returning null so a future call site cannot
        // forget the rule; the donation is marked `is_80g_eligible = false`
        // inside the service before the throw. It does NOT touch
        // `anonymous` — no PAN means no receipt, not anonymity (corrected
        // 2026-08-10). Gupt Daan is the donor's own checkbox.
        //
        // This is NOT an error: a PAN-less donation is a perfectly valid
        // donation that simply carries no tax receipt. Swallow it, log at
        // info, and send nothing — the `donation.confirmed` message has
        // already gone out from PaymentCaptureService.
        try {
            $receipt = $receiptService->generateReceipt($this->donation);
        } catch (Donation80GNotEligibleException $e) {
            Log::info('80G receipt skipped — donation is not 80G eligible', [
                'donation_id' => $this->donation->id,
                'reason' => $e->reason,
            ]);

            return;
        }

        $this->donation->update([
            'receipt_generated' => true,
        ]);

        Log::info('80G receipt generated', [
            'donation_id' => $this->donation->id,
            'receipt_number' => $receipt->receipt_number,
        ]);

        $this->donation->loadMissing('devotee');
        $devotee = $this->donation->devotee;

        if (! $devotee || ! $receipt->pdf_path) {
            return;
        }

        // Build the PDF URL once. WhatsApp needs a publicly-fetchable link
        // (presigned R2 URL, max 7 days per S3 spec). Email attaches the
        // bytes inline so the URL is irrelevant there — both channels
        // come out of the same dispatch.
        $receiptPdfUrl = null;
        try {
            $receiptPdfUrl = Storage::disk('r2_private')->temporaryUrl(
                $receipt->pdf_path,
                now()->addDays(7),
            );
        } catch (\Throwable $e) {
            Log::error('Failed to presign 80G PDF URL', [
                'donation_id' => $this->donation->id,
                'error' => $e->getMessage(),
            ]);
        }

        // PDF bytes for email attachment; pulled from R2 once.
        $attachments = [];
        try {
            $pdfBytes = Storage::disk('r2_private')->get($receipt->pdf_path);
            $receiptFilename = str_replace('/', '-', "80G_Receipt_{$receipt->receipt_number}.pdf");
            $attachments[] = [
                'data' => $pdfBytes,
                'name' => $receiptFilename,
                'mime' => 'application/pdf',
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to read 80G PDF bytes for attachment', [
                'donation_id' => $this->donation->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Single dispatch — every enabled NotificationTemplate for
        // 'donation.receipt_80g' fires. If no rows exist or none are
        // enabled, nothing sends. That's intentional.
        try {
            app(NotificationService::class)->dispatch(
                'donation.receipt_80g',
                [
                    'devotee' => $devotee,
                    'receipt' => array_merge($receipt->toArray(), [
                        'amount_formatted' => inr_money($this->donation->amount),
                    ]),
                    'donation' => $this->donation,
                    // Publish the devotee name under BOTH top-level keys
                    // so any admin-chosen token resolves: `name` (the
                    // ergonomic short form that auth.otp and
                    // devotee.birthday also use) AND `donor_name` (the
                    // legacy/explicit form). Without the `name` alias
                    // templates that use {{ name }} resolve to empty
                    // and Meta rejects them with (#131008).
                    'name' => $devotee->name,
                    'donor_name' => $devotee->name,
                    'amount' => inr_money($this->donation->amount),
                    'amount_formatted' => inr_money($this->donation->amount),
                    'receipt_pdf_url' => $receiptPdfUrl,
                    'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
                    '_attachments' => $attachments,
                ],
                idempotencyKey: "donation:{$this->donation->id}:receipt_80g",
            );

            $receipt->update([
                'emailed_at' => now(),
                'whatsapp_sent_at' => $receiptPdfUrl ? now() : null,
            ]);

            Log::info('80G receipt dispatched via NotificationService', [
                'donation_id' => $this->donation->id,
                'email' => $devotee->email,
                'has_pdf_url' => $receiptPdfUrl !== null,
            ]);
        } catch (\Throwable $e) {
            Log::error('80G receipt dispatch failed', [
                'donation_id' => $this->donation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
