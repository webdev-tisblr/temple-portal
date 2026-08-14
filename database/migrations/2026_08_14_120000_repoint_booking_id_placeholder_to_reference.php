<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stop printing 36-character UUIDs into devotees' messages.
 *
 * `booking_id` was mapped to `booking.id`, which for seva bookings is a
 * UUID — unreadable in a WhatsApp message and impossible for a devotee to
 * quote back. It now resolves to `booking.booking_reference`: the receipt
 * number when the receipt has been generated, otherwise a short reference
 * in the same shape (SevaBooking::getBookingReferenceAttribute).
 *
 * The registry auto-fills this correctly for templates created from now
 * on, but a saved template keeps whatever map it was saved with — so the
 * live rows need rewriting here. Only the seva keys are touched: hall and
 * store bookings already map their own booking_number / order_number, and
 * an integer id is readable anyway.
 */
return new class extends Migration
{
    private const SEVA_KEYS = [
        'seva.booking.confirmed',
        'seva.booking.reminder',
        'seva.greeting_card',
        'darshan.photo.uploaded',
    ];

    public function up(): void
    {
        $this->remap('booking.id', 'booking.booking_reference');
    }

    public function down(): void
    {
        $this->remap('booking.booking_reference', 'booking.id');
    }

    /**
     * Rewrite the `booking_id` entry of every seva template whose map
     * currently points at $from. Row-by-row rather than a JSON UPDATE so
     * a template with a hand-edited map is left alone.
     */
    private function remap(string $from, string $to): void
    {
        $templates = DB::table('temple_notification_templates')
            ->whereIn('key', self::SEVA_KEYS)
            ->get(['id', 'placeholder_map']);

        foreach ($templates as $template) {
            $map = json_decode((string) ($template->placeholder_map ?? ''), true);

            if (! is_array($map) || ($map['booking_id'] ?? null) !== $from) {
                continue;
            }

            $map['booking_id'] = $to;

            DB::table('temple_notification_templates')
                ->where('id', $template->id)
                ->update(['placeholder_map' => json_encode($map)]);
        }
    }
};
