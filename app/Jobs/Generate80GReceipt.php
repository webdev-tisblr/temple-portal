<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Donation;
use App\Services\ReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        Log::info("80G receipt generated", [
            'donation_id' => $this->donation->id,
            'receipt_number' => $receipt->receipt_number,
        ]);

        // Generate greeting card if donation type has a template
        try {
            $cardPath = app(\App\Services\GreetingCardService::class)->generate($this->donation);
            if ($cardPath) {
                Log::info("Greeting card generated", ['donation_id' => $this->donation->id]);
            }
        } catch (\Exception $e) {
            Log::error("Greeting card generation failed", ['error' => $e->getMessage()]);
        }

        $this->donation->loadMissing('devotee');
        $devotee = $this->donation->devotee;

        if (! $devotee) {
            return;
        }

        // Send receipt via email
        if ($devotee->email && $receipt->pdf_path) {
            $this->sendReceiptEmail($devotee, $receipt);
        }

        // Send receipt via WhatsApp
        if ($devotee->phone && $receipt->pdf_path) {
            // WhatsApp's media-by-URL flow needs a publicly-fetchable URL.
            // Generate a signed temporary URL on the private R2 bucket
            // (max 7 days per R2/S3 presigner). Long enough for reliable
            // delivery; users keep the PDF attachment in their chat anyway.
            try {
                $pdfUrl = Storage::disk('r2_private')->temporaryUrl(
                    $receipt->pdf_path,
                    now()->addDays(7),
                );
            } catch (\Throwable $e) {
                Log::error('Failed to generate WhatsApp PDF URL', [
                    'donation_id' => $this->donation->id,
                    'error' => $e->getMessage(),
                ]);
                $pdfUrl = null;
            }

            if ($pdfUrl) {
                $filename = str_replace('/', '-', "80G_Receipt_{$receipt->receipt_number}.pdf");

                SendWhatsAppMessage::dispatch($devotee->phone, 'document', [
                    'url' => $pdfUrl,
                    'filename' => $filename,
                    'caption' => "Thank you for your donation of ₹" . number_format((float) $this->donation->amount) . ". Here is your 80G receipt.",
                ]);

                $receipt->update(['whatsapp_sent_at' => now()]);
            }

            // Send greeting card via WhatsApp if configured. The card download
            // route (/donate/greeting-card/{id}) is permanent and unauth, so
            // unlike the PDF we don't need a presigned URL here.
            $cardConfig = $this->donation->donationType?->greeting_card_config ?? [];
            if (($cardConfig['send_via_whatsapp'] ?? true) && $this->donation->greeting_card_path) {
                $cardUrl = url('/donate/greeting-card/' . $this->donation->id);
                SendWhatsAppMessage::dispatch($devotee->phone, 'document', [
                    'url' => $cardUrl,
                    'filename' => 'Greeting_Card.png',
                    'caption' => 'Here is your personalised greeting card from Shree Pataliya Hanumanji Seva Trust.',
                ]);
            }
        }
    }

    private function sendReceiptEmail($devotee, $receipt): void
    {
        try {
            // Pull PDF bytes from R2 — no local filesystem path available.
            $pdfBytes = Storage::disk('r2_private')->get($receipt->pdf_path);
            $receiptNumber = $receipt->receipt_number;

            // Optional greeting-card attachment also lives on r2_private.
            $cardBytes = null;
            if ($this->donation->greeting_card_path) {
                try {
                    $cardBytes = Storage::disk('r2_private')->get($this->donation->greeting_card_path);
                } catch (\Throwable $e) {
                    $cardBytes = null;
                }
            }

            $attachments = [[
                'data' => $pdfBytes,
                'name' => str_replace('/', '-', "80G_Receipt_{$receiptNumber}.pdf"),
                'mime' => 'application/pdf',
            ]];
            if ($cardBytes) {
                $attachments[] = [
                    'data' => $cardBytes,
                    'name' => 'Greeting_Card.png',
                    'mime' => 'image/png',
                ];
            }

            // Single dispatch — every enabled NotificationTemplate for
            // 'donation.receipt_80g' fires (email today; WhatsApp / push
            // when the admin enables them).
            app(\App\Services\Notifications\NotificationService::class)->dispatch(
                'donation.receipt_80g',
                [
                    'devotee' => $devotee,
                    'receipt' => array_merge($receipt->toArray(), [
                        'amount_formatted' => number_format((float) $this->donation->amount, 2),
                    ]),
                    'donation' => $this->donation,
                    'trust_name' => \App\Models\SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
                    '_attachments' => $attachments,
                ],
            );

            $receipt->update(['emailed_at' => now()]);

            Log::info("80G receipt dispatched via NotificationService", [
                'donation_id' => $this->donation->id,
                'email' => $devotee->email,
            ]);
        } catch (\Exception $e) {
            Log::error("80G receipt dispatch failed", [
                'donation_id' => $this->donation->id,
                'email' => $devotee->email ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
