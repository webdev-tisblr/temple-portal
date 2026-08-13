<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Jobs\GenerateSevaGreetingCard;
use App\Models\SevaBooking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Seva greeting cards, sent ON THE DAY the seva is performed (2026-08-13).
 *
 * Until now the card went out the instant payment cleared, which for a seva
 * booked weeks ahead arrived long before the seva itself. The trust wants it to
 * land on the morning of the seva, so this sweeps the day's confirmed bookings
 * at 07:30 IST.
 *
 * A daily sweep rather than per-booking scheduled rows: the time is the same
 * for everyone, so there is nothing per-booking worth storing, nothing to
 * cancel when a booking is cancelled, and no schedule rows that can leak. The
 * shape mirrors NotifyBookingDayDevoteesOfDarshanPhoto, which already answers
 * the same "who is booked for today" question.
 *
 * This decides WHEN, never WHETHER: GenerateSevaGreetingCard renders the card
 * and fires `seva.greeting_card`, and nothing is delivered unless an admin has
 * enabled a template for that trigger. A seva with no artwork produces nothing
 * at all.
 */
class SendSevaDayGreetingCards extends Command
{
    protected $signature = 'seva:send-day-of-cards
                            {--date= : Run as if today were this date (YYYY-MM-DD), for rehearsal}
                            {--dry-run : List what would be sent without sending}';

    protected $description = 'Send seva greeting cards for every seva taking place today';

    public function handle(): int
    {
        $date = $this->option('date')
            ? \Illuminate\Support\Carbon::parse((string) $this->option('date'))->toDateString()
            : now()->toDateString();

        $dry = (bool) $this->option('dry-run');

        // Confirmed only, so a cancelled or refunded booking needs no special
        // handling here — it simply stops matching.
        $bookings = SevaBooking::query()
            ->whereDate('booking_date', $date)
            ->where('status', BookingStatus::CONFIRMED)
            ->with(['seva', 'devotee'])
            ->orderBy('id')
            ->get()
            ->filter(fn (SevaBooking $b): bool => $b->devotee !== null);

        if ($bookings->isEmpty()) {
            $this->info("No confirmed seva bookings for {$date}.");

            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY RUN] ' : '')."Seva bookings on {$date}: {$bookings->count()}");

        $sent = 0;
        $skipped = 0;

        foreach ($bookings as $booking) {
            // Cheap pre-check purely so the dry run and the log are honest
            // about what will actually happen; the job re-checks anyway.
            $hasArtwork = $booking->seva
                && $booking->seva->greeting_card_template
                && $booking->seva->greeting_card_config;

            if (! $hasArtwork) {
                $this->line("  #{$booking->id} — {$booking->seva?->name} — no card artwork, skipped");
                $skipped++;

                continue;
            }

            $this->line("  #{$booking->id} — {$booking->seva?->name} — {$booking->devotee?->name}");

            if ($dry) {
                $sent++;

                continue;
            }

            try {
                // Idempotent by its own key ("seva-booking:{id}:greeting_card"),
                // so a re-run — or a booking that also sent at capture because
                // it was made today — cannot card the same devotee twice.
                GenerateSevaGreetingCard::dispatchSync($booking);
                $sent++;
            } catch (\Throwable $e) {
                // One bad booking must not stop the morning's run.
                $this->error("  #{$booking->id} failed: {$e->getMessage()}");
                Log::error('SendSevaDayGreetingCards: booking failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;
            }
        }

        $this->newLine();
        $this->info(($dry ? 'Would send' : 'Sent').": {$sent}   Skipped: {$skipped}");

        Log::info('SendSevaDayGreetingCards ran', [
            'date' => $date,
            'sent' => $sent,
            'skipped' => $skipped,
            'dry_run' => $dry,
        ]);

        return self::SUCCESS;
    }
}
