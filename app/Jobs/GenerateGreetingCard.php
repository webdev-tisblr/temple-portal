<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Donation;
use App\Models\SystemSetting;
use App\Services\GreetingCardService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates the donation greeting card PNG (when the donation type has a
 * card template configured) and dispatches it as its OWN notification.
 *
 * This is deliberately separate from Generate80GReceipt: the 80G receipt
 * and the greeting card are two independent deliverables with their own
 * triggers and template sets. The receipt PDF goes out under
 * `donation.receipt_80g`; the card goes out here under
 * `donation.greeting_card`. Neither rides along inside the other's message.
 *
 * NOTHING is sent unless the admin has created + enabled a
 * NotificationTemplate for `donation.greeting_card` on a channel — same
 * rule as the rest of the platform. Which channels fire is decided SOLELY
 * by which `donation.greeting_card` templates are enabled (Email / WhatsApp
 * / SMS); there are no per-donation-type send toggles. The donation type's
 * `show_on_thankyou` flag only affects the thank-you page, not delivery.
 */
class GenerateGreetingCard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public Donation $donation,
    ) {}

    public function handle(GreetingCardService $greetingCards, NotificationService $notifications): void
    {
        // Generate the card. Returns null when the donation type has no
        // template configured — in that case there is simply no card for
        // this donation and nothing to send.
        $cardPath = $greetingCards->generate($this->donation);
        if (! $cardPath) {
            return;
        }

        Log::info('Greeting card generated', ['donation_id' => $this->donation->id]);

        $this->donation->loadMissing('devotee');
        $devotee = $this->donation->devotee;
        if (! $devotee) {
            return;
        }

        // WhatsApp needs a publicly-fetchable link. The greeting-card web
        // route is permanent + unauth (regenerates on miss), so no presign.
        $greetingCardUrl = url('/donate/greeting-card/' . $this->donation->id);

        // PNG bytes for the email attachment; pulled from R2 private once.
        $attachments = [];
        try {
            $cardBytes = Storage::disk('r2_private')->get($cardPath);
            if ($cardBytes) {
                $attachments[] = [
                    'data' => $cardBytes,
                    'name' => 'Greeting_Card.png',
                    'mime' => 'image/png',
                ];
            }
        } catch (\Throwable $e) {
            // Card image is best-effort for email; WhatsApp still gets the URL.
            Log::warning('Failed to read greeting card bytes for attachment', [
                'donation_id' => $this->donation->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $notifications->dispatch(
                'donation.greeting_card',
                [
                    'devotee' => $devotee,
                    'donation' => $this->donation,
                    // Publish the devotee name under BOTH keys so any
                    // admin-chosen token resolves — mirrors receipt_80g.
                    'name' => $devotee->name,
                    'donor_name' => $devotee->name,
                    'amount' => (string) $this->donation->amount,
                    'amount_formatted' => number_format((float) $this->donation->amount, 2),
                    'greeting_card_url' => $greetingCardUrl,
                    'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
                    '_attachments' => $attachments,
                ],
                idempotencyKey: "donation:{$this->donation->id}:greeting_card",
            );

            Log::info('Greeting card dispatched via NotificationService', [
                'donation_id' => $this->donation->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Greeting card dispatch failed', [
                'donation_id' => $this->donation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
