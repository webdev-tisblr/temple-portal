<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HallBooking;
use App\Models\SystemSetting;
use App\Services\HallInvoiceService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Builds the hall-booking PDF invoice via HallInvoiceService and fires
 * the `hall.booking.confirmed` notification carrying the invoice (PDF
 * attached for email, permanent signed invoice_pdf_url for WhatsApp
 * document headers / message bodies).
 *
 * Lives outside the PaymentCaptureService transaction so a slow PDF
 * render can never hold the Payment row's lock. Dispatched by
 * PaymentCaptureService::markCaptured after the booking row commits
 * to status='confirmed', and by the web test-mode booking path.
 *
 * This is the ONLY dispatch site for hall.booking.confirmed (the old
 * duplicate in HallBookingController was removed 2026-08-04).
 *
 * A PDF failure must NOT swallow the confirmation: the trigger still
 * fires without the attachment — the signed URL regenerates the PDF
 * on first click.
 *
 * `$sendNotification = false` is used by the self-heal regeneration
 * path so re-downloading the PDF doesn't re-notify the customer.
 */
class GenerateHallInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public HallBooking $booking,
        public bool $sendNotification = true,
    ) {}

    public function handle(HallInvoiceService $invoices, NotificationService $notifications): void
    {
        $bookingNumber = $invoices->bookingNumberFor($this->booking);
        $pdfBytes = null;

        try {
            $path = $invoices->generateInvoice($this->booking);
            $pdfBytes = Storage::disk('r2_private')->get($path);

            Log::info('Hall invoice generated', [
                'booking_id' => $this->booking->id,
                'booking_number' => $bookingNumber,
            ]);
        } catch (\Throwable $e) {
            Log::error('Hall invoice PDF failed — confirming without attachment', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $this->sendNotification) {
            return;
        }

        $this->booking->refresh()->loadMissing('hall', 'devotee');

        $bookingTypeLabel = match ($this->booking->booking_type) {
            'full_day' => 'Full Day',
            'half_day_morning' => 'Half Day (Morning)',
            'half_day_evening' => 'Half Day (Evening)',
            default => ucfirst($this->booking->booking_type),
        };

        $context = [
            'booking' => array_merge($this->booking->toArray(), [
                'booking_number' => $bookingNumber,
                'booking_type_label' => $bookingTypeLabel,
                'booking_date' => $this->booking->booking_date?->format('d M Y'),
                'total_amount_formatted' => number_format((float) $this->booking->total_amount, 2),
                'contact_phone' => $this->booking->contact_phone,
                'contact_name' => $this->booking->contact_name,
                'hall' => $this->booking->hall ? $this->booking->hall->toArray() : null,
            ]),
            'devotee' => $this->booking->devotee,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            // Permanent signed link — regenerates the PDF on miss;
            // WhatsApp document headers point at this.
            'invoice_pdf_url' => URL::signedRoute('hall.invoice.link', ['booking' => $this->booking->id]),
        ];

        // Key must be ABSENT (not []) when there is no PDF: contexts with
        // an _attachments key always send inline, never via the outbox.
        if ($pdfBytes !== null) {
            $context['_attachments'] = [[
                'data' => $pdfBytes,
                'name' => "HallBooking_{$bookingNumber}.pdf",
                'mime' => 'application/pdf',
            ]];
        }

        $notifications->dispatch(
            'hall.booking.confirmed',
            $context,
            idempotencyKey: "hall-booking:{$this->booking->id}:confirmed",
        );

        Log::info('Hall booking confirmation dispatched via NotificationService', [
            'booking_id' => $this->booking->id,
            'has_pdf' => $pdfBytes !== null,
        ]);
    }
}
