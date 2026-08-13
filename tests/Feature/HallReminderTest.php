<?php

namespace Tests\Feature;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\HallReminderRule;
use App\Models\HallReminderSchedule;
use App\Models\NotificationTemplate;
use App\Services\HallReminderScheduler;
use App\Services\PaymentCaptureService;
use Database\Factories\DevoteeFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hall booking reminders (2026-08-13).
 *
 * These are the FIRST reminder tests in the repo — the seva system, which
 * this mirrors, has none. Two of the cases below exist because the seva side
 * shipped the bug first: a two-column unique key that let only one rule per
 * offset ever fire, and an observer that could roll back a live payment
 * capture by throwing inside its transaction.
 */
class HallReminderTest extends TestCase
{
    use RefreshDatabase;

    private function hall(array $overrides = []): Hall
    {
        return Hall::create(array_merge([
            'name' => 'Community Hall',
            'name_gu' => 'સમુદાય હૉલ',
            'price_per_day' => 5000,
            'capacity' => 100,
            'is_active' => true,
            'day_start_time' => '09:00',
        ], $overrides));
    }

    private function booking(Hall $hall, array $overrides = []): HallBooking
    {
        $start = now()->addDays(10)->toDateString();

        return HallBooking::create(array_merge([
            'devotee_id' => DevoteeFactory::new()->create()->id,
            'hall_id' => $hall->id,
            'booking_date' => $start,
            'end_date' => $start,
            'days_count' => 1,
            'booking_type' => 'full_day',
            'purpose' => 'Wedding',
            'contact_name' => 'Ramesh',
            'contact_phone' => '9876543210',
            'total_amount' => 5000,
            'status' => 'confirmed',
            'payment_id' => PaymentFactory::new()->create()->id,
        ], $overrides));
    }

    private function rule(Hall $hall, array $overrides = []): HallReminderRule
    {
        return HallReminderRule::create(array_merge([
            'hall_id' => $hall->id,
            'offset_minutes' => 1440,
            'recipient_type' => HallReminderRule::RECIPIENT_DEVOTEE,
            'channel' => NotificationTemplate::CHANNEL_PUSH,
            'title_gu' => 'આવતીકાલે હૉલ બુકિંગ',
            'body_gu' => '{{hall_name}} — {{booking_date}}',
            'is_active' => true,
        ], $overrides));
    }

    /**
     * 1. The fire time is counted back from the hall's OWN day-start on the
     *    FIRST booked day — not from midnight, and not from the end date.
     */
    public function test_fire_at_counts_back_from_the_halls_day_start_on_the_first_day(): void
    {
        $hall = $this->hall(['day_start_time' => '11:00']);
        $this->rule($hall, ['offset_minutes' => 1440]);

        $start = now()->addDays(10)->toDateString();
        $booking = $this->booking($hall, [
            'booking_date' => $start,
            // Multi-day: the LAST day must not move the reminder.
            'end_date' => now()->addDays(12)->toDateString(),
            'days_count' => 3,
        ]);

        app(HallReminderScheduler::class)->generateForBooking($booking);

        $row = HallReminderSchedule::where('hall_booking_id', $booking->id)->firstOrFail();
        $this->assertSame(
            now()->parse($start.' 11:00')->subDay()->format('Y-m-d H:i'),
            $row->fire_at->format('Y-m-d H:i'),
        );
    }

    /** 2. A rule whose moment has already passed creates nothing. */
    public function test_a_rule_already_in_the_past_creates_no_row(): void
    {
        $hall = $this->hall();
        // 1 week before a booking that is 2 days away = already gone.
        $this->rule($hall, ['offset_minutes' => 10080]);

        $start = now()->addDays(2)->toDateString();
        $booking = $this->booking($hall, ['booking_date' => $start, 'end_date' => $start]);

        $this->assertSame(0, app(HallReminderScheduler::class)->generateForBooking($booking));
        $this->assertSame(0, HallReminderSchedule::where('hall_booking_id', $booking->id)->count());
    }

    /**
     * 3. Two rules at the SAME offset — devotee and admin, both "1 day
     *    before" — each get a row. The seva schedule table still carries a
     *    stale two-column unique that would collapse these into one, which is
     *    exactly why this table has a three-column key.
     */
    public function test_two_rules_at_the_same_offset_each_get_a_row(): void
    {
        $hall = $this->hall();
        $this->rule($hall);
        $this->rule($hall, [
            'recipient_type' => HallReminderRule::RECIPIENT_ADMIN_ROLE,
            'recipient_value' => 'Trust Manager',
        ]);

        // No explicit generate call: the observer already did it when the
        // booking was created confirmed, which is the real production path.
        $booking = $this->booking($hall);

        $this->assertSame(2, HallReminderSchedule::where('hall_booking_id', $booking->id)->count());
    }

    /** 4. Generation is idempotent — the backfill can run on every deploy. */
    public function test_generation_is_idempotent(): void
    {
        $hall = $this->hall();
        $this->rule($hall);
        $booking = $this->booking($hall);
        $scheduler = app(HallReminderScheduler::class);

        // The observer created the row; the backfill then runs over the same
        // booking on every deploy and must add nothing.
        $this->assertSame(1, HallReminderSchedule::where('hall_booking_id', $booking->id)->count());
        $this->assertSame(0, $scheduler->generateForBooking($booking), 'a re-run must create nothing');
        $this->assertSame(0, $scheduler->generateForBooking($booking));
        $this->assertSame(1, HallReminderSchedule::where('hall_booking_id', $booking->id)->count());
    }

    /** 5. Cancelling a booking retires its pending rows. */
    public function test_cancelling_a_booking_skips_pending_rows(): void
    {
        $hall = $this->hall();
        $this->rule($hall);
        $booking = $this->booking($hall);
        app(HallReminderScheduler::class)->generateForBooking($booking);

        $booking->update(['status' => 'cancelled']);

        $this->assertSame(
            HallReminderSchedule::STATUS_SKIPPED,
            HallReminderSchedule::where('hall_booking_id', $booking->id)->firstOrFail()->status,
        );
    }

    /**
     * 6. THE ONE THAT MATTERS. The observer runs inside
     *    PaymentCaptureService::markCaptured()'s transaction. On 2026-08-04
     *    the seva equivalent threw there and rolled back a live capture — the
     *    devotee's money was taken and the booking was not confirmed. A lost
     *    reminder is recoverable by backfill; a lost capture is not.
     */
    public function test_a_throwing_scheduler_cannot_break_a_payment_capture(): void
    {
        $this->app->bind(HallReminderScheduler::class, function () {
            return new class extends HallReminderScheduler
            {
                public function generateForBooking(HallBooking $booking): int
                {
                    throw new \RuntimeException('scheduler exploded');
                }
            };
        });

        $hall = $this->hall();
        $this->rule($hall);

        $payment = PaymentFactory::new()->create(['status' => 'created']);
        $booking = $this->booking($hall, ['status' => 'pending', 'payment_id' => $payment->id]);

        app(PaymentCaptureService::class)->markCaptured($payment, 'pay_hall_reminder_boom');

        $this->assertSame('captured', $payment->refresh()->status->value, 'the capture must survive');
        $this->assertSame('confirmed', $booking->refresh()->status, 'the booking must still confirm');
    }

    /** 7. Nothing sends without an enabled template. */
    public function test_a_whatsapp_rule_with_no_template_sends_nothing(): void
    {
        $hall = $this->hall();
        $this->rule($hall, [
            'channel' => NotificationTemplate::CHANNEL_WHATSAPP,
            'notification_template_id' => null,
        ]);
        $booking = $this->booking($hall);
        app(HallReminderScheduler::class)->generateForBooking($booking);

        HallReminderSchedule::where('hall_booking_id', $booking->id)
            ->update(['fire_at' => now()->subMinute()]);

        $this->artisan('hall:dispatch-reminders')->assertSuccessful();

        // Marked sent (there was nothing to send), and no log row was written.
        $this->assertSame(
            HallReminderSchedule::STATUS_SENT,
            HallReminderSchedule::where('hall_booking_id', $booking->id)->firstOrFail()->status,
        );
        $this->assertSame(0, \DB::table('temple_notification_logs')->count());
    }

    /**
     * 7b. The hirer's WhatsApp reminder goes to the CONTACT number on the
     *     booking, which is the person actually running the event and is
     *     often not whoever paid. Falls back to the account number when the
     *     booking carries none.
     */
    public function test_the_hirers_whatsapp_reminder_targets_the_booking_contact_number(): void
    {
        $hall = $this->hall();
        $template = NotificationTemplate::create([
            'key' => 'hall.booking.reminder',
            'label' => 'Hall reminder (WA)',
            'channel' => NotificationTemplate::CHANNEL_WHATSAPP,
            'is_enabled' => true,
            'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
            'wa_template_name' => 'hall_reminder',
            'wa_template_language' => 'gu',
        ]);
        $this->rule($hall, [
            'channel' => NotificationTemplate::CHANNEL_WHATSAPP,
            'notification_template_id' => $template->id,
        ]);

        $booking = $this->booking($hall, ['contact_phone' => '9812345678']);
        HallReminderSchedule::where('hall_booking_id', $booking->id)
            ->update(['fire_at' => now()->subMinute()]);

        $this->artisan('hall:dispatch-reminders')->assertSuccessful();

        $log = \DB::table('temple_notification_logs')
            ->where('template_key', 'hall.booking.reminder')->first();

        $this->assertNotNull($log, 'the send must be attempted and logged');
        $this->assertSame(NotificationTemplate::RECIPIENT_FIXED_PHONE, $log->recipient_strategy);
        $this->assertSame('9812345678', $log->recipient_value);

        // ...and with no contact number on the booking, it falls back to the
        // devotee's own record rather than sending to nobody. contact_phone
        // is NOT NULL, so "none" means an empty string in practice.
        \DB::table('temple_notification_logs')->delete();
        $second = $this->booking($hall, ['contact_phone' => '', 'booking_date' => now()->addDays(11)->toDateString(), 'end_date' => now()->addDays(11)->toDateString()]);
        HallReminderSchedule::where('hall_booking_id', $second->id)
            ->update(['fire_at' => now()->subMinute()]);

        $this->artisan('hall:dispatch-reminders')->assertSuccessful();

        $log = \DB::table('temple_notification_logs')
            ->where('template_key', 'hall.booking.reminder')->first();
        $this->assertNotNull($log);
        $this->assertSame(NotificationTemplate::RECIPIENT_DEVOTEE, $log->recipient_strategy);
    }

    /** 8. A row whose booking has since been cancelled is skipped, not sent. */
    public function test_the_dispatcher_skips_a_cancelled_booking(): void
    {
        $hall = $this->hall();
        $this->rule($hall);
        $booking = $this->booking($hall);
        app(HallReminderScheduler::class)->generateForBooking($booking);

        // Straight to the DB so the observer does not retire the row for us —
        // this asserts the dispatcher's own guard, not the observer's.
        \DB::table('temple_hall_bookings')->where('id', $booking->id)->update(['status' => 'cancelled']);
        HallReminderSchedule::where('hall_booking_id', $booking->id)->update(['fire_at' => now()->subMinute()]);

        $this->artisan('hall:dispatch-reminders')->assertSuccessful();

        $this->assertSame(
            HallReminderSchedule::STATUS_SKIPPED,
            HallReminderSchedule::where('hall_booking_id', $booking->id)->firstOrFail()->status,
        );
    }

    /** 9. A reminder that is more than 12 hours late is binned, never sent. */
    public function test_a_badly_late_reminder_is_skipped(): void
    {
        $hall = $this->hall();
        $this->rule($hall);
        $booking = $this->booking($hall);
        app(HallReminderScheduler::class)->generateForBooking($booking);

        HallReminderSchedule::where('hall_booking_id', $booking->id)
            ->update(['fire_at' => now()->subHours(20)]);

        $this->artisan('hall:dispatch-reminders')->assertSuccessful();

        $this->assertSame(
            HallReminderSchedule::STATUS_SKIPPED,
            HallReminderSchedule::where('hall_booking_id', $booking->id)->firstOrFail()->status,
        );
    }
}
