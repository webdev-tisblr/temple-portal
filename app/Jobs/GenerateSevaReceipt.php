<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\Donation80GNotEligibleException;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\SevaBookingContext;
use App\Services\ReceiptService;
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
 *
 * ── 80G (2026-08-31) ────────────────────────────────────────────────
 * A booking whose devotee ticked the 80G box gets the STATUTORY receipt
 * INSTEAD of the plain one — one receipt per booking, never both. The
 * confirmation trigger, its idempotency key and `receipt_pdf_url` are
 * identical either way; only the document behind the link changes, so
 * every template an admin has already configured keeps working.
 *
 * The strict PAN rule can refuse: no readable, format-valid PAN means no
 * 80G receipt and NO statutory number burnt. That is a fall-back to the
 * ordinary seva receipt, NOT a failure — a paid booking must never end up
 * with no receipt at all.
 */
class GenerateSevaReceipt implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public SevaBooking $booking,
        public bool $sendNotification = true,
    ) {}

    public function handle(
        SevaReceiptService $receipts,
        NotificationService $notifications,
        ReceiptService $statutoryReceipts,
    ): void {
        $pdfBytes = null;
        $attachmentName = null;
        $receiptNumber = null;

        try {
            // ── ALLOCATION SITE #5 ──────────────────────────────────
            // The only seva path that can burn a statutory number. It is
            // reached exactly once per booking (the job is dispatched from
            // the post-commit side of PaymentCaptureService, and
            // generateForSevaBooking short-circuits on an existing row).
            if ($this->booking->wants_80g) {
                try {
                    $receipt = $statutoryReceipts->generateForSevaBooking($this->booking);
                    $pdfBytes = Storage::disk('r2_private')->get($receipt->pdf_path);
                    $receiptNumber = $receipt->receipt_number;
                    $attachmentName = '80G_Receipt_'.str_replace('/', '-', (string) $receiptNumber).'.pdf';

                    Log::info('Seva 80G receipt generated', [
                        'booking_id' => $this->booking->id,
                        'receipt_number' => $receiptNumber,
                    ]);
                } catch (Donation80GNotEligibleException $e) {
                    // Asked for, refused (almost always: no valid PAN).
                    // generateForSevaBooking has already recorded the
                    // verdict and burnt nothing. Fall through to the
                    // ordinary receipt so the devotee still gets a
                    // document for a payment they have already made.
                    Log::info('Seva 80G declined — issuing the plain seva receipt', [
                        'booking_id' => $this->booking->id,
                        'reason' => $e->reason,
                    ]);
                }
            }

            if ($receiptNumber === null) {
                $path = $receipts->generateReceipt($this->booking);
                $pdfBytes = Storage::disk('r2_private')->get($path);
                $receiptNumber = $this->booking->receipt_number;
                $attachmentName = "Seva_Receipt_{$receiptNumber}.pdf";

                Log::info('Seva receipt generated', [
                    'booking_id' => $this->booking->id,
                    'receipt_number' => $receiptNumber,
                ]);
            }
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
        // selectedProduct too (2026-08-18): the confirmation now carries the
        // same product + image keys the reminder does, and an unloaded
        // relation would resolve every one of them to a blank.
        $this->booking->refresh()->loadMissing('devotee', 'seva.assignee', 'selectedProduct');

        $context = [
            'booking' => array_merge($this->booking->toArray(), [
                // Accessors do not survive toArray(), and `id` is a UUID
                // that means nothing to a reader.
                'booking_reference' => $this->booking->booking_reference,
                // Publish every language, not just gu/en: templates map
                // `booking.seva_name` and NotificationContext upgrades that
                // to the _hi/_en sibling for a Hindi/English devotee,
                // falling back to this Gujarati value when the translation
                // is blank. Hindi had no path here at all before.
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
                'total_amount_formatted' => inr_money($this->booking->total_amount),
            ]),
            'devotee' => $this->booking->devotee,
            'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            // The 80G number when one was issued, else the plain
            // SEVA-… one. Falls back to booking_reference rather than an
            // empty string: a blank WhatsApp parameter makes Meta reject
            // the entire message.
            'receipt_number' => $receiptNumber ?: $this->booking->booking_reference,
            // Permanent signed link — regenerates the PDF on miss, so it
            // outlives the 7-day r2_private sweep (unlike a presign).
            // UNCHANGED by the 80G work on purpose: the route resolves to
            // whichever document this booking actually has, so every
            // template already mapping {{ receipt_pdf_url }} keeps working.
            'receipt_pdf_url' => URL::signedRoute('seva.receipt.link', ['booking' => $this->booking->id]),
        ];

        // product_* names/price/image plus the resolved {{ image_url }} —
        // shared with the reminder so the two can never drift.
        $context = array_merge($context, SevaBookingContext::values($this->booking));

        // Key must be ABSENT (not []) when there is no PDF: contexts with
        // an _attachments key always send inline, never via the outbox.
        if ($pdfBytes !== null) {
            $context['_attachments'] = [[
                'data' => $pdfBytes,
                'name' => $attachmentName ?? "Seva_Receipt_{$this->booking->receipt_number}.pdf",
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
            'is_80g' => $this->booking->is_80g_eligible,
        ]);
    }
}
