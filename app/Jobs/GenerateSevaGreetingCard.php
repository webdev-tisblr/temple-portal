<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SevaBooking;
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
 * Generates the seva-booking greeting card PNG (when the seva has a
 * card template configured) and dispatches it as its OWN notification —
 * the exact mirror of GenerateGreetingCard for donations.
 *
 * The card is rendered in the devotee's preferred language: the seva
 * carries per-locale background images (gu default, hi/en optional)
 * and GreetingCardService picks the right one.
 *
 * NOTHING is sent unless the admin has created + enabled a
 * NotificationTemplate for `seva.greeting_card` on a channel. Sevas
 * without a template image simply produce no card and no message.
 */
class GenerateSevaGreetingCard implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public SevaBooking $booking,
    ) {}

    public function handle(GreetingCardService $greetingCards, NotificationService $notifications): void
    {
        $cardPath = $greetingCards->generateForSevaBooking($this->booking);
        if (! $cardPath) {
            return;
        }

        Log::info('Seva greeting card generated', ['booking_id' => $this->booking->id]);

        $this->booking->refresh()->loadMissing('devotee', 'seva');
        $devotee = $this->booking->devotee;
        if (! $devotee) {
            return;
        }

        // WhatsApp needs a publicly-fetchable link. The greeting-card web
        // route is permanent + unauth (regenerates on miss), so no presign.
        $greetingCardUrl = url('/seva/greeting-card/'.$this->booking->id);

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
            Log::warning('Failed to read seva greeting card bytes for attachment', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        $bookedForName = $this->booking->devotee_name_for_seva ?: $devotee->name;

        try {
            $notifications->dispatch(
                'seva.greeting_card',
                [
                    'devotee' => $devotee,
                    'booking' => array_merge($this->booking->toArray(), [
                        'booking_reference' => $this->booking->booking_reference,
                        'seva_name' => $this->booking->seva?->name_gu,
                        'seva_name_gu' => $this->booking->seva?->name_gu,
                        'seva_name_hi' => $this->booking->seva?->name_hi,
                        'seva_name_en' => $this->booking->seva?->name_en,
                        'booking_date' => $this->booking->booking_date?->format('d/m/Y'),
                        'slot_label' => $this->booking->slot_time_label,
                    ]),
                    // Devotee name under BOTH keys so any admin-chosen
                    // token resolves — mirrors the donation card context.
                    'name' => $bookedForName,
                    'donor_name' => $bookedForName,
                    'seva_name' => $this->booking->seva?->name_gu,
                    'seva_name_gu' => $this->booking->seva?->name_gu,
                    'seva_name_hi' => $this->booking->seva?->name_hi,
                    'seva_name_en' => $this->booking->seva?->name_en,
                    'amount' => (string) $this->booking->total_amount,
                    'amount_formatted' => number_format((float) $this->booking->total_amount, 2),
                    'greeting_card_url' => $greetingCardUrl,
                    'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
                    '_attachments' => $attachments,
                ],
                idempotencyKey: "seva-booking:{$this->booking->id}:greeting_card",
            );

            Log::info('Seva greeting card dispatched via NotificationService', [
                'booking_id' => $this->booking->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Seva greeting card dispatch failed', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
