<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BookingStatus;
use App\Models\DailyDarshanPhoto;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * When the day's Daily Darshan photo is uploaded, send it to every
 * devotee holding a confirmed booking for that date on a seva with the
 * "Send daily darshan to booked devotees" toggle enabled.
 *
 * Fires the `darshan.photo.uploaded` trigger once per devotee (deduped
 * across multiple bookings — the earliest booking supplies the seva /
 * slot context). Delivery channels + wording are whatever templates the
 * admin has enabled on that trigger; nothing sends without one.
 *
 * Idempotency: dispatch() is keyed `darshan:{photo}:{devotee}`, so photo
 * re-saves or a job retry inside the dedupe window can't double-send.
 */
class NotifyBookingDayDevoteesOfDarshanPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $photoId,
    ) {}

    public function handle(NotificationService $notifier): void
    {
        $photo = DailyDarshanPhoto::find($this->photoId);

        if ($photo === null || ! $photo->is_active) {
            return;
        }

        $sevaIds = Seva::where('send_darshan_on_booking_date', true)->pluck('id');
        if ($sevaIds->isEmpty()) {
            return;
        }

        $bookings = SevaBooking::query()
            ->whereIn('seva_id', $sevaIds)
            ->whereDate('booking_date', $photo->captured_on)
            ->where('status', BookingStatus::CONFIRMED)
            ->with(['devotee', 'seva'])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (SevaBooking $b) => $b->devotee !== null)
            ->unique('devotee_id');

        if ($bookings->isEmpty()) {
            return;
        }

        $imageUrl = image_url($photo->image_path);
        $trustName = SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust');

        foreach ($bookings as $booking) {
            $notifier->dispatch(
                'darshan.photo.uploaded',
                [
                    'devotee' => $booking->devotee,
                    'booking' => $booking,
                    'photo' => $photo,
                    'darshan_image_url' => $imageUrl,
                    'darshan_date' => $photo->captured_on?->format('d M Y'),
                    'trust_name' => $trustName,
                ],
                idempotencyKey: "darshan:{$photo->id}:{$booking->devotee_id}",
            );
        }

        Log::info('Darshan booking-day notifications dispatched', [
            'photo_id' => $photo->id,
            'captured_on' => $photo->captured_on?->toDateString(),
            'devotees' => $bookings->count(),
        ]);
    }
}
