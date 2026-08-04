<?php

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Models\Payment;
use App\Models\SevaBooking;
use App\Services\PaymentCaptureService;
use App\Services\SevaReceiptService;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Coverage for the 2026-08-04 trigger merge: payment captured → receipt
 * PDF generated → ONE `seva.booking.confirmed` notification carrying the
 * receipt. The old separate `seva.receipt` trigger is retired.
 */
class ReceiptTriggerMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_private');
        Mail::fake();
    }

    private function enableConfirmedEmailTemplate(): void
    {
        NotificationTemplate::create([
            'key' => 'seva.booking.confirmed',
            'channel' => 'email',
            'label' => 'Test — seva confirmed (with receipt)',
            'is_enabled' => true,
            'subject' => 'Confirmed — {{ seva_name }}',
            'body' => '<p>{{ devotee_name }} — {{ receipt_number }} — {{ receipt_pdf_url }}</p>',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
            'placeholder_map' => [
                'devotee_name' => 'devotee.name',
                'seva_name' => 'booking.seva_name',
                'receipt_number' => 'receipt_number',
                'receipt_pdf_url' => 'receipt_pdf_url',
            ],
        ]);
    }

    public function test_capture_fires_single_merged_trigger_with_receipt(): void
    {
        $this->enableConfirmedEmailTemplate();

        $payment = PaymentFactory::new()->create();
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        app(PaymentCaptureService::class)->markCaptured($payment, 'pay_merge_1');

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertNotNull($booking->receipt_number, 'receipt should be generated during capture');
        $this->assertNotNull($booking->receipt_path);

        $this->assertDatabaseHas('temple_notification_logs', [
            'template_key' => 'seva.booking.confirmed',
        ]);
        $this->assertDatabaseMissing('temple_notification_logs', [
            'template_key' => 'seva.receipt',
        ]);
        $this->assertSame(
            1,
            DB::table('temple_notification_logs')->where('template_key', 'seva.booking.confirmed')->count(),
            'exactly one confirmation per channel'
        );
    }

    public function test_confirmation_survives_pdf_generation_failure(): void
    {
        $this->enableConfirmedEmailTemplate();

        $this->mock(SevaReceiptService::class, function ($mock) {
            $mock->shouldReceive('generateReceipt')->andThrow(new \RuntimeException('mPDF exploded'));
        });

        $payment = PaymentFactory::new()->create();
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
        ]);

        app(PaymentCaptureService::class)->markCaptured($payment, 'pay_merge_2');

        $this->assertSame('confirmed', $booking->fresh()->status->value);
        // The confirmation must still go out — without an attachment, but
        // with the signed receipt_pdf_url that regenerates on click.
        $this->assertDatabaseHas('temple_notification_logs', [
            'template_key' => 'seva.booking.confirmed',
        ]);
    }

    public function test_signed_receipt_route_rejects_unsigned_and_honours_signature(): void
    {
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => PaymentFactory::new()->create()->id,
            'status' => 'pending',
        ]);

        // Unsigned → 403 regardless of booking state.
        $this->get("/r/seva-receipt/{$booking->id}")->assertForbidden();

        // Signed but booking still pending → the status gate 404s, which
        // proves the signature check passed. (A full 302 assertion would
        // need a presign-capable fake disk.)
        $this->get(URL::signedRoute('seva.receipt.link', ['booking' => $booking->id]))
            ->assertNotFound();
    }

    public function test_capture_survives_duplicate_offset_reminder_rules(): void
    {
        // 2026-08-04 prod incident: two active reminder rules sharing one
        // offset made the scheduler's firstOrCreate collide with the
        // (booking, offset) unique index INSIDE the capture transaction —
        // rolling back the payment capture itself. The devotee paid,
        // got a 500, and the booking stayed pending.
        $payment = PaymentFactory::new()->create();
        $booking = SevaBookingFactory::new()->create([
            'payment_id' => $payment->id,
            'status' => 'pending',
            'booking_date' => now()->addDays(10)->toDateString(),
        ]);

        foreach ([1, 2] as $i) {
            \App\Models\SevaReminderRule::create([
                'seva_id' => $booking->seva_id,
                'is_active' => true,
                'offset_minutes' => 1440, // same offset on both rules
                'recipient_type' => 'devotee',
                'channels' => ['push'],
                'title_gu' => "યાદ અપાવવું {$i}",
                'body_gu' => 'સેવા આવતીકાલે છે',
            ]);
        }

        app(PaymentCaptureService::class)->markCaptured($payment, 'pay_dup_rules');

        $this->assertSame('captured', $payment->fresh()->status->value);
        $this->assertSame('confirmed', $booking->fresh()->status->value);
        $this->assertSame(
            2,
            DB::table('temple_seva_reminder_schedules')
                ->where('seva_booking_id', $booking->id)
                ->where('offset', '1440m')
                ->count(),
            'one schedule row PER RULE — same-offset rules both fire'
        );
    }

    public function test_migration_rekeys_receipt_template_and_disables_plain_duplicate(): void
    {
        // Recreate the pre-merge world: enabled plain confirmed + enabled
        // receipt template on the same channel.
        NotificationTemplate::create([
            'key' => 'seva.booking.confirmed', 'channel' => 'email',
            'label' => 'Plain confirmed', 'is_enabled' => true,
            'subject' => 's', 'body' => 'b',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
        ]);
        $receiptRow = NotificationTemplate::create([
            'key' => 'seva.receipt', 'channel' => 'email',
            'label' => 'Receipt', 'is_enabled' => true,
            'subject' => 's', 'body' => 'b',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
        ]);

        $migration = require database_path('migrations/2026_08_04_140000_merge_seva_receipt_trigger_into_booking_confirmed.php');
        $migration->up();

        $rows = NotificationTemplate::where('key', 'seva.booking.confirmed')->where('channel', 'email')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(0, NotificationTemplate::where('key', 'seva.receipt')->count());

        $enabled = $rows->where('is_enabled', true);
        $this->assertCount(1, $enabled, 'only one enabled row per channel after merge');
        $this->assertSame($receiptRow->id, $enabled->first()->id, 'the receipt-carrying row wins');
    }
}
