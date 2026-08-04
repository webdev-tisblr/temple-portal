<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\SevaBooking;
use App\Services\SevaReminderScheduler;
use Illuminate\Support\Facades\Log;

/**
 * Keeps the seva reminder schedule in step with a booking's lifecycle,
 * whatever path changes it — payment capture (PaymentCaptureService),
 * an admin creating/editing a booking in Filament, or any future flow.
 * Hooking the model rather than each call site means no confirm path can
 * forget to schedule reminders.
 */
class SevaBookingObserver
{
    public function __construct(private readonly SevaReminderScheduler $scheduler)
    {
    }

    /**
     * A booking created already-confirmed (eg an admin entering a paid
     * booking by hand) gets its reminders straight away.
     */
    public function created(SevaBooking $booking): void
    {
        if ($this->isConfirmed($booking)) {
            $this->generateSafely($booking);
        }
    }

    /**
     * Generate reminders the moment a booking transitions INTO confirmed
     * (the payment-capture path), and clear pending reminders when it
     * leaves confirmed (cancelled / refunded).
     */
    public function updated(SevaBooking $booking): void
    {
        if (! $booking->wasChanged('status')) {
            return;
        }

        if ($this->isConfirmed($booking)) {
            $this->generateSafely($booking);
            return;
        }

        // Left the confirmed state — drop any reminders not yet sent.
        $this->scheduler->cancelPendingFor($booking);
    }

    /**
     * Reminder scheduling must NEVER break the money path. This observer
     * fires inside PaymentCaptureService's transaction — an uncaught
     * exception here rolls back the payment capture itself (2026-08-04
     * prod incident). Reminders can be regenerated later by the
     * seva:backfill-reminder-schedule command; a lost capture cannot.
     */
    private function generateSafely(SevaBooking $booking): void
    {
        try {
            $this->scheduler->generateForBooking($booking);
        } catch (\Throwable $e) {
            Log::error('SevaBookingObserver: reminder generation failed — booking confirm continues', [
                'booking_id' => $booking->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function isConfirmed(SevaBooking $booking): bool
    {
        $status = $booking->status instanceof \BackedEnum
            ? $booking->status->value
            : (string) $booking->status;

        return $status === 'confirmed';
    }
}
