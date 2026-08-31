<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Donation;
use App\Models\Receipt80G;
use App\Models\SevaBooking;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The devotee's 80G receipts — BOTH sources in one list.
 *
 * The app used to build this screen client-side by fetching
 * /donations/history and filtering on `receipt_generated`, which by
 * construction could never show a seva 80G receipt. Reading the
 * Receipt80G rows directly is both cheaper and source-agnostic: a
 * devotee has one 80G folder, not one per product.
 */
class Receipt80GController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $devoteeId = $request->user()->id;

        // Captured payments only. A receipt should never exist for an
        // uncaptured payment, but the filter also stops a stray row (a
        // seeded test fixture, a half-rolled-back capture) surfacing as a
        // tax document.
        $donationIds = Donation::where('devotee_id', $devoteeId)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->pluck('id');

        $sevaBookingIds = SevaBooking::where('devotee_id', $devoteeId)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->pluck('id');

        $receipts = Receipt80G::where(fn ($q) => $q
            ->whereIn('donation_id', $donationIds)
            ->orWhereIn('seva_booking_id', $sevaBookingIds))
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $receipts->getCollection()->map(fn (Receipt80G $receipt) => [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'financial_year' => $receipt->financial_year,
            'amount' => (float) $receipt->amount,
            // 'donation' | 'seva' — lets the app badge each row.
            'source_type' => $receipt->source_type,
            // Seva name, or campaign/purpose for a donation. May be null
            // for a plain general donation, which has nothing to name.
            'source_label' => $receipt->source_label,
            // The date the MONEY moved, on both sources — this is the date
            // the deduction is claimed against.
            'date' => optional($receipt->donation_date)->toDateString()
                ?? optional($receipt->created_at)->toDateString(),
            // Always true in practice: the download endpoint regenerates a
            // swept PDF on demand, so this must NOT depend on pdf_path.
            'pdf_available' => true,
        ])->values();

        return $this->success($data, 'Success', 200, [
            'current_page' => $receipts->currentPage(),
            'last_page' => $receipts->lastPage(),
            'total' => $receipts->total(),
        ]);
    }

    /**
     * ⚠ ALLOCATION-FREE. Reached only through an EXISTING Receipt80G, and
     * uses ensurePdf(), which takes an issued receipt and can only refresh
     * its cached file. Never call generateReceipt() /
     * generateForSevaBooking() here — downloading a receipt must never burn
     * a statutory serial.
     */
    public function download(Request $request, Receipt80G $receipt)
    {
        $devoteeId = $request->user()->id;

        // Ownership runs through whichever source issued the receipt.
        // Exactly one of the two FKs is set — see the Receipt80G model.
        $owner = $receipt->isForSeva()
            ? SevaBooking::find($receipt->seva_booking_id)?->devotee_id
            : Donation::find($receipt->donation_id)?->devotee_id;

        if ($owner === null || $owner !== $devoteeId) {
            return $this->error('Unauthorized', 403);
        }

        // Self-heal: the nightly sweep deletes the object and NULLs
        // pdf_path, so non-null == present. No R2 ->exists() probe — S3
        // HEADs hang (see AppServiceProvider).
        if (! $receipt->pdf_path) {
            try {
                $receipt = app(ReceiptService::class)->ensurePdf($receipt);
            } catch (\Throwable $e) {
                Log::error('80G receipt regen failed (api)', [
                    'receipt_number' => $receipt->receipt_number,
                    'error' => $e->getMessage(),
                ]);
            }

            if (! $receipt->pdf_path) {
                return $this->error('Receipt could not be generated. Try again shortly.', 500);
            }
        }

        $filename = str_replace('/', '-', (string) $receipt->receipt_number).'.pdf';

        return private_file_redirect($receipt->pdf_path, $filename);
    }
}
