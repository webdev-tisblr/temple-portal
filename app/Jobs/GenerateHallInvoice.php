<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\HallBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use App\Helpers\NumberToWords;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the hall-booking PDF invoice, writes it to R2_private, and
 * fires the `hall.booking.confirmed` notification with the PDF attached.
 *
 * Lives outside the PaymentCaptureService transaction so a slow PDF
 * render can never hold the Payment row's lock. Dispatched by
 * PaymentCaptureService::markCaptured after the booking row commits
 * to status='confirmed'.
 *
 * Replaces the inline HallBookingController::generateHallInvoice +
 * emailHallInvoice methods. Web success callbacks previously called
 * those methods directly; they now go through markCaptured → here,
 * so API + web have identical post-capture behaviour.
 *
 * `$sendNotification = false` is used by the self-heal regeneration
 * path so re-downloading the PDF doesn't re-email the customer.
 */
class GenerateHallInvoice implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public HallBooking $booking,
        public bool $sendNotification = true,
    ) {}

    public function handle(NotificationService $notifications): void
    {
        try {
            $this->booking->loadMissing('hall', 'devotee');

            $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');
            $trustAddress = SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205');

            $bookingNumber = 'HALL-' . $this->booking->id . '-' . $this->booking->created_at->format('Ymd');

            $pdf = Pdf::loadView('invoices.hall-booking-invoice', [
                'booking' => $this->booking,
                'trust_name' => $trustName,
                'trust_address' => $trustAddress,
                'booking_number' => $bookingNumber,
                'amount_in_words' => NumberToWords::convert((float) $this->booking->total_amount),
            ]);
            $pdf->setPaper('a4');

            $directory = 'hall-invoices';
            $filename = "{$bookingNumber}.pdf";
            $path = "{$directory}/{$filename}";

            Storage::disk('r2_private')->put($path, $pdf->output());

            $this->booking->update(['invoice_path' => $path]);

            Log::info('Hall invoice generated', [
                'booking_id' => $this->booking->id,
                'booking_number' => $bookingNumber,
            ]);

            if (! $this->sendNotification) {
                return;
            }

            $pdfBytes = Storage::disk('r2_private')->get($path);
            $bookingTypeLabel = match ($this->booking->booking_type) {
                'full_day' => 'Full Day',
                'half_day_morning' => 'Half Day (Morning)',
                'half_day_evening' => 'Half Day (Evening)',
                default => ucfirst($this->booking->booking_type),
            };

            $notifications->dispatch(
                'hall.booking.confirmed',
                [
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
                    'trust_name' => $trustName,
                    '_attachments' => [[
                        'data' => $pdfBytes,
                        'name' => "HallBooking_{$bookingNumber}.pdf",
                        'mime' => 'application/pdf',
                    ]],
                ],
                idempotencyKey: "hall-booking:{$this->booking->id}:confirmed",
            );

            Log::info('Hall booking invoice dispatched via NotificationService', [
                'booking_id' => $this->booking->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Hall invoice generation failed', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
