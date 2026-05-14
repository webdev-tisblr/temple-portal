<?php

declare(strict_types=1);

namespace App\Jobs;

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
 * Builds the 80G receipt PDF (and optional greeting card) for a
 * captured donation and hands everything to NotificationService::dispatch
 * with the URLs and PDF bytes exposed as placeholders / attachments.
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
        $receipt = $receiptService->generateReceipt($this->donation);

        $this->donation->update([
            'receipt_generated' => true,
            'receipt_id' => $receipt->id,
        ]);

        Log::info('80G receipt generated', [
            'donation_id' => $this->donation->id,
            'receipt_number' => $receipt->receipt_number,
        ]);

        // Optional greeting card — only generated if the donation type
        // has a card template configured by the admin.
        try {
            $cardPath = app(\App\Services\GreetingCardService::class)->generate($this->donation);
            if ($cardPath) {
                Log::info('Greeting card generated', ['donation_id' => $this->donation->id]);
            }
        } catch (\Exception $e) {
            Log::error('Greeting card generation failed', ['error' => $e->getMessage()]);
        }

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

        // Greeting card URL — the public download route is permanent
        // and unauth, so no presign required.
        $greetingCardUrl = $this->donation->greeting_card_path
            ? url('/donate/greeting-card/' . $this->donation->id)
            : null;

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

            if ($this->donation->greeting_card_path) {
                try {
                    $cardBytes = Storage::disk('r2_private')->get($this->donation->greeting_card_path);
                    if ($cardBytes) {
                        $attachments[] = [
                            'data' => $cardBytes,
                            'name' => 'Greeting_Card.png',
                            'mime' => 'image/png',
                        ];
                    }
                } catch (\Throwable $e) {
                    // Card is optional; ignore.
                }
            }
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
                        'amount_formatted' => number_format((float) $this->donation->amount, 2),
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
                    'amount' => (string) $this->donation->amount,
                    'amount_formatted' => number_format((float) $this->donation->amount, 2),
                    'receipt_pdf_url' => $receiptPdfUrl,
                    'greeting_card_url' => $greetingCardUrl,
                    'trust_name' => SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
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
