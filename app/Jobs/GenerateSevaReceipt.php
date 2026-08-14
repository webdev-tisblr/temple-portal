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
use Illuminate\Support\Facades\URL;

/**
 * Builds the seva-booking receipt PDF, writes it to R2 private, and
 * fires the SINGLE `seva.booking.confirmed` notification carrying the
 * receipt (PDF attached for email, permanent signed receipt_pdf_url
 * for WhatsApp document headers / message bodies).
 *
 * This is the merged flow (2026-08-04): payment captured → receipt
 * generated → one confirmation trigger. The old separate `seva.receipt`
 * trigger is retired; its template rows were re-keyed by migration.
 *
 * Mirrors GenerateHallInvoice: lives outside the PaymentCaptureService
 * transaction so a slow PDF render can never hold the Payment row's
 * lock. Dispatched by PaymentCaptureService::markCaptured after the
 * booking row commits to status='confirmed'.
 *
 * A PDF failure must NOT swallow the confirmation: the trigger still
 * fires without the attachment — the signed URL regenerates the PDF
 * on first click, so the link in the message stays serviceable.
 *
 * `$sendNotification = false` is used by self-heal regeneration paths
 * so re-downloading the PDF doesn't re-notify the devotee.
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
        $pdfBytes = null;

        try {
            $path = $receipts->generateReceipt($this->booking);
            $pdfBytes = Storage::disk('r2_private')->get($path);

            Log::info('Seva receipt generated', [
                'booking_id' => $this->booking->id,
                'receipt_number' => $this->booking->receipt_number,
            ]);
        } catch (\Throwable $e) {
            Log::error('Seva receipt PDF failed — confirming without attachment', [
                'booking_id' => $this->booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        if (! $this->sendNotification) {
            return;
        }

        // seva.assignee too — booking goes into the context as an
        // ARRAY, so the assignee_name/phone placeholders only resolve
        // if the relation is loaded before toArray().
        $this->booking->refresh()->loadMissing('devotee', 'seva.assignee');

        $context = [
            'booking' => array_merge($this->booking->toArray(), [
                // Publish every language, not just gu/en: templates map
                // `booking.seva_name` and NotificationContext upgrades that
                // to the _hi/_en sibling for a Hindi/English devotee,
                // falling back to this Gujarati value when the translation
                // is blank. Hindi had no path here at all before.
                // Accessors do not survive toArray(), and `id` is a UUID
                // that means nothing to a reader.
                'booking_reference' => $this->booking->booking_reference,
                'seva_name' => $this->booking->seva?->name_gu,
                'seva_name_gu' => $this->booking->seva?->name_gu,
                'seva_name_hi' => $this->booking->seva?->name_hi,
                'seva_name_en' => $this->booking->seva?->name_en,
                'booking_date' => $this->booking->booking_date?->format('d M Y'),
                // slot_time_label is an accessor and does NOT survive
                // toArray(); templates map it under three names (legacy
                // confirmed-, legacy receipt-, and raw-column paths).
                'slot_label' => $this->booking->slot_time_label,
                'slot_time_label' => $this->booking->slot_time_label,
                'slot_time' => $this->booking->slot_time_label,
                'total_amount_formatted' => number_format((float) $this->booking->total_amount, 2),
            ]),
            'devotee' => $this->booking->devotee,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            'receipt_number' => $this->booking->receipt_number,
            // Permanent signed link — regenerates the PDF on miss, so it
            // outlives the 7-day r2_private sweep (unlike a presign).
            'receipt_pdf_url' => URL::signedRoute('seva.receipt.link', ['booking' => $this->booking->id]),
        ];

        // Key must be ABSENT (not []) when there is no PDF: contexts with
        // an _attachments key always send inline, never via the outbox.
        if ($pdfBytes !== null) {
            $context['_attachments'] = [[
                'data' => $pdfBytes,
                'name' => "Seva_Receipt_{$this->booking->receipt_number}.pdf",
                'mime' => 'application/pdf',
            ]];
        }

        $notifications->dispatch(
            'seva.booking.confirmed',
            $context,
            idempotencyKey: "seva-booking:{$this->booking->id}:confirmed",
        );

        Log::info('Seva booking confirmation dispatched via NotificationService', [
            'booking_id' => $this->booking->id,
            'has_pdf' => $pdfBytes !== null,
        ]);
    }
}
