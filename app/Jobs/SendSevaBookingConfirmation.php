<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SevaBooking;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendSevaBookingConfirmation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public SevaBooking $booking,
    ) {}

    public function handle(WhatsAppService $whatsAppService): void
    {
        $this->booking->loadMissing('devotee', 'seva');

        $devotee = $this->booking->devotee;
        $seva = $this->booking->seva;

        if (!$devotee || !$devotee->phone) {
            Log::warning('SevaBookingConfirmation: no phone for booking', [
                'booking_id' => $this->booking->id,
            ]);
            return;
        }

        $sevaName = $seva->name_en ?? $seva->name_gu;
        $bookingDate = $this->booking->booking_date->format('d M Y');
        $slotTime = $this->booking->slot_time ?? 'Any time';
        $bookingId = substr($this->booking->id, 0, 8);

        $success = $whatsAppService->sendTemplateMessage(
            $devotee->phone,
            'seva_booking_confirmed',
            'en',
            [
                [
                    'type' => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => $sevaName],
                        ['type' => 'text', 'text' => $bookingDate],
                        ['type' => 'text', 'text' => $slotTime],
                        ['type' => 'text', 'text' => $bookingId],
                    ],
                ],
            ]
        );

        if ($success) {
            Log::info("Seva booking confirmation sent via WhatsApp", [
                'booking_id' => $this->booking->id,
                'phone' => $devotee->phone,
            ]);
        }
    }
}
