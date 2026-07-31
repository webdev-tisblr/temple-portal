<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use App\Services\SevaReceiptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Builds the seva-booking receipt PDF, writes it to R2 private, and
 * fires the `seva.receipt` notification with the PDF attached.
 *
 * Mirrors GenerateHallInvoice: lives outside the PaymentCaptureService
 * transaction so a slow PDF render can never hold the Payment row's
 * lock. Dispatched by PaymentCaptureService::markCaptured after the
 * booking row commits to status='confirmed'.
 *
 * Nothing sends unless an admin has created + enabled a
 * NotificationTemplate for `seva.receipt` (zero auto-fire policy) —
 * the separate `seva.booking.confirmed` trigger is unaffected.
 *
 * `$sendNotification = false` is used by the self-heal regeneration
 * path so re-downloading the PDF doesn't re-notify the devotee.
 */
class GenerateSevaReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public SevaBooking $booking,
        public bool $sendNotification = true,
    ) {}

    public function handle(SevaReceiptService $receipts, NotificationService $notifications): void
    {
        try {
            $path = $receipts->generateReceipt($this->booking);
            // seva.assignee too — booking goes into the context as an
            // ARRAY, so the assignee_name/phone placeholders only resolve
            // if the relation is loaded before toArray().
            $this->booking->refresh()->loadMissing('devotee', 'seva.assignee');

            Log::info('Seva receipt generated', [
                'booking_id' => $this->booking->id,
                'receipt_number' => $this->booking->receipt_number,
            ]);

            if (! $this->sendNotification) {
                return;
            }

            $pdfBytes = Storage::disk('r2_private')->get($path);
            // Presigned link for WhatsApp document headers — the file may
            // be swept after 7 days, but downloads regenerate on miss via
            // the app/web endpoints, so the attachment window matches 80G.
            $pdfUrl = Storage::disk('r2_private')->temporaryUrl($path, now()->addDays(7));

            $trustName = SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust');

            $notifications->dispatch(
                'seva.receipt',
                [
                    'booking' => array_merge($this->booking->toArray(), [
                        'seva_name' => $this->booking->seva?->name_gu,
                        'seva_name_en' => $this->booking->seva?->name_en,
                        'booking_date' => $this->booking->booking_date?->format('d M Y'),
                        'slot_label' => $this->booking->slot_time_label,
                        'total_amount_formatted' => number_format((float) $this->booking->total_amount, 2),
                    ]),
                    'devotee' => $this->booking->devotee,
                    'trust_name' => $trustName,
                    'receipt_number' => $this->booking->receipt_number,
                    'receipt_pdf_url' => $pdfUrl,
                    '_attachments' => [[
                        'data' => $pdfBytes,
                        'name' => "Seva_Receipt_{$this->booking->receipt_number}.pdf",
                        'mime' => 'application/pdf',
                    ]],
                ],
                idempotencyKey: "seva-booking:{$this->booking->id}:receipt",
            );

            Log::info('Seva receipt dispatched via NotificationService', [
                'booking_id' => $this->booking->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Seva receipt generation failed', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
