<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateHallInvoice;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\HallAvailabilityService;
use App\Services\PaymentCaptureService;
use App\Services\RazorpayService;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Support\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class HallBookingController extends Controller
{
    /**
     * Halls listing page. Lists every active hall and links to its
     * per-hall booking page. The menu's "હોલ બુકિંગ" entry points here.
     */
    public function hallsList(): View
    {
        $halls = Hall::where('is_active', true)->orderBy('name')->get();

        SEOMeta::setTitle('હૉલ બુકિંગ — શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતાળિયા હનુમાનજી મંદિરના વિશાળ હોલ ઓનલાઈન બુક કરો.');

        return view('pages.hall-booking.list', compact('halls'));
    }

    /**
     * Per-hall page — gallery, amenities, pricing and the booking form.
     */
    public function hallShow(Hall $hall): View
    {
        abort_unless($hall->is_active, 404);

        SEOMeta::setTitle("{$hall->name} — હૉલ બુકિંગ — શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ");
        SEOMeta::setDescription("શ્રી પાતાળિયા હનુમાનજી મંદિર {$hall->name} ઓનલાઈન બુક કરો.");

        return view('pages.hall-booking.index', compact('hall'));
    }

    public function __construct(
        private readonly HallAvailabilityService $availability,
    ) {}

    /**
     * Availability verdict for a single date or a date RANGE.
     * Un-wrapped payload (kept — the Alpine picker reads it directly);
     * `reason_code`, `days`, `total_amount` and `conflicting_dates` are
     * additive.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        // Full-day only (2026-08-04): the half-day option was removed.
        // `booking_type`/`type` params are still accepted-and-ignored so
        // older cached pages don't 422.
        $request->validate([
            'hall_id' => ['required', 'integer', 'exists:temple_halls,id'],
            'date' => ['required', 'date'],
            'end_date' => ['nullable', 'date'],
        ]);

        $hall = Hall::findOrFail($request->integer('hall_id'));
        $start = Carbon::parse((string) $request->date)->toDateString();
        $end = $request->filled('end_date')
            ? Carbon::parse((string) $request->end_date)->toDateString()
            : $start;

        $verdict = $this->availability->checkRange($hall, $start, $end);
        $price = $this->availability->priceFor($hall, $start, $end);

        return response()->json([
            'available' => $verdict['ok'],
            'message' => $verdict['ok']
                ? 'હૉલ ઉપલબ્ધ છે.'
                : ($verdict['reason'] ?? 'આ તારીખ માટે હૉલ પહેલેથી બુક છે.'),
            'reason_code' => $verdict['reason_code'],
            // No `display` — this is a verdict on the range the devotee
            // selected, so `message` is always shown. `display` belongs to
            // list rows only (see App\Enums\UnavailableReason::visible).
            'conflicting_dates' => $verdict['conflicting_dates'],
            'days' => $price['days'],
            'price_per_day' => $price['price_per_day'],
            // Additive — the picker shows a GST line when gst_rate is not null.
            'subtotal_amount' => $price['subtotal'],
            'gst_rate' => $price['gst_rate'],
            'gst_amount' => $price['gst_amount'],
            'total_amount' => $price['total'],
            'max_booking_days' => $hall->maxBookingDays(),
        ]);
    }

    /**
     * Website twin of GET /api/v1/halls/{hall}/next-available (item 4.4).
     */
    public function nextAvailable(Request $request): JsonResponse
    {
        $request->validate([
            'hall_id' => ['required', 'integer', 'exists:temple_halls,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $hall = Hall::findOrFail($request->integer('hall_id'));
        $found = $this->availability->nextAvailable($hall, null, 365, (int) $request->integer('days', 1) ?: 1);

        return response()->json([
            'found' => $found !== null,
            'date' => $found['date'] ?? null,
            'end_date' => $found['end_date'] ?? null,
        ]);
    }

    public function book(Request $request): View
    {
        $validated = $request->validate([
            'hall_id' => ['required', 'integer', 'exists:temple_halls,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            // Item 4.2 — nullable, so a single-day submit is unchanged.
            'end_date' => ['nullable', 'date', 'after_or_equal:booking_date'],
            'purpose' => ['required', 'string', 'max:500'],
            'expected_guests' => ['nullable', 'integer'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:20'],
        ]);

        // Full-day only (2026-08-04). The column keeps its default; any
        // submitted booking_type is ignored.
        $validated['booking_type'] = 'full_day';

        $devotee = Auth::guard('devotee')->user();
        $hall = Hall::where('id', $validated['hall_id'])->where('is_active', true)->firstOrFail();

        $validated['booking_date'] = Carbon::parse($validated['booking_date'])->toDateString();
        $validated['end_date'] = ! empty($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->toDateString()
            : $validated['booking_date'];

        // One verdict covers blackouts, the cut-off (4.3), max_booking_days
        // and overlapping bookings across the whole range.
        $verdict = $this->availability->checkRange($hall, $validated['booking_date'], $validated['end_date']);
        if (! $verdict['ok']) {
            return back()->withErrors([
                'booking_date' => $verdict['reason'] ?? 'આ તારીખ માટે હૉલ પહેલેથી બુક છે.',
            ]);
        }

        // Server-authoritative price: flat price_per_day × days.
        $price = $this->availability->priceFor($hall, $validated['booking_date'], $validated['end_date']);
        $validated['days_count'] = $price['days'];
        $totalAmount = $price['total'];

        // TEST MODE — skip Razorpay, direct confirm
        if (config('razorpay.test_mode')) {
            return $this->bookTestMode($validated, $devotee, $hall, $totalAmount, $price);
        }

        // REAL PAYMENT MODE
        try {
            $result = DB::transaction(function () use ($validated, $devotee, $hall, $totalAmount, $price) {
                // Race-safe re-check under a row lock — the pre-existing
                // hall double-booking hole (two devotees both paying for
                // the same date) is closed here, mirroring the seva path.
                if (! $this->availability->hasRangeCapacityForUpdate($hall, $validated['booking_date'], $validated['end_date'])) {
                    throw new \App\Exceptions\SlotUnavailableException((string) __('availability.hall_taken_race'));
                }

                $paymentId = (string) Str::uuid();
                $receipt = 'HALL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                $razorpayService = app(RazorpayService::class);
                $amountInPaise = (int) round($totalAmount * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'hall_id' => $hall->id,
                    'type' => 'hall_booking',
                ]);

                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => $razorpayOrder->id,
                    'amount' => $totalAmount,
                    'currency' => 'INR',
                    'status' => 'created',
                    'description' => "Hall Booking - {$hall->name} - {$validated['booking_date']}",
                ]);

                $booking = HallBooking::create([
                    'devotee_id' => $devotee->id,
                    'hall_id' => $hall->id,
                    'booking_date' => $validated['booking_date'],
                    'end_date' => $validated['end_date'],
                    'days_count' => $validated['days_count'],
                    'booking_type' => $validated['booking_type'],
                    'purpose' => $validated['purpose'],
                    'expected_guests' => $validated['expected_guests'] ?? null,
                    'contact_name' => $validated['contact_name'],
                    'contact_phone' => $validated['contact_phone'],
                    'total_amount' => $totalAmount,
                    // GST snapshot alongside the gross total, so the invoice
                    // can print Taxable Value / CGST / SGST without recomputing
                    // from a setting that may have changed since.
                    'subtotal_amount' => $price['subtotal'],
                    'gst_rate' => $price['gst_rate'],
                    'gst_amount' => $price['gst_amount'],
                    'status' => 'pending',
                    'payment_id' => $payment->id,
                ]);

                return [
                    'booking' => $booking,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                ];
            });

            return view('pages.seva.checkout', [
                'razorpayKeyId' => SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'orderId' => $result['razorpay_order']->id,
                'amount' => (int) round($totalAmount * 100),
                'currency' => 'INR',
                'description' => "હૉલ બુકિંગ — {$hall->name}",
                'devoteeName' => $devotee->name,
                'devoteePhone' => $devotee->phone,
                'devoteeEmail' => $devotee->email ?? '',
                'successUrl' => route('hall.booking.success'),
                'failureUrl' => route('hall.booking.failure'),
            ]);

        } catch (\App\Exceptions\SlotUnavailableException $e) {
            return back()->withErrors(['booking_date' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Hall booking failed', ['error' => $e->getMessage()]);

            return back()->withErrors(['booking' => 'બુકિંગ બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    /**
     * @param  array{days:int, price_per_day:float, subtotal:float, gst_rate:float|null, gst_amount:float, total:float}  $price
     */
    private function bookTestMode(array $validated, $devotee, Hall $hall, float $totalAmount, array $price): View
    {
        try {
            $result = DB::transaction(function () use ($validated, $devotee, $hall, $totalAmount, $price) {
                // Same locked re-check as the live path — test mode must not
                // be the one place that can double-book a hall.
                if (! $this->availability->hasRangeCapacityForUpdate($hall, $validated['booking_date'], $validated['end_date'])) {
                    throw new \App\Exceptions\SlotUnavailableException((string) __('availability.hall_taken_race'));
                }

                $paymentId = (string) Str::uuid();

                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => 'test_'.Str::random(14),
                    'amount' => $totalAmount,
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => 'test',
                    'paid_at' => now(),
                    'description' => "Hall Booking - {$hall->name} (Test)",
                ]);

                $booking = HallBooking::create([
                    'devotee_id' => $devotee->id,
                    'hall_id' => $hall->id,
                    'booking_date' => $validated['booking_date'],
                    'end_date' => $validated['end_date'],
                    'days_count' => $validated['days_count'],
                    'booking_type' => $validated['booking_type'],
                    'purpose' => $validated['purpose'],
                    'expected_guests' => $validated['expected_guests'] ?? null,
                    'contact_name' => $validated['contact_name'],
                    'contact_phone' => $validated['contact_phone'],
                    'total_amount' => $totalAmount,
                    // GST snapshot alongside the gross total, so the invoice
                    // can print Taxable Value / CGST / SGST without recomputing
                    // from a setting that may have changed since.
                    'subtotal_amount' => $price['subtotal'],
                    'gst_rate' => $price['gst_rate'],
                    'gst_amount' => $price['gst_amount'],
                    'status' => 'confirmed',
                    'payment_id' => $payment->id,
                ]);

                return ['booking' => $booking, 'payment' => $payment];
            });

            Log::info('Hall booking confirmed (test mode)', ['booking_id' => $result['booking']->id]);

            // Same post-capture path as live payments: PDF + the single
            // hall.booking.confirmed dispatch live in the job.
            try {
                GenerateHallInvoice::dispatchSync($result['booking']);
            } catch (\Throwable $e) {
                Log::error('Test-mode hall invoice job failed', [
                    'booking_id' => $result['booking']->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return view('pages.hall-booking.success', [
                'verified' => true,
                'booking' => $result['booking']->load('hall'),
            ]);

        } catch (\Exception $e) {
            Log::error('Hall booking failed (test mode)', ['error' => $e->getMessage()]);

            return back()->withErrors(['booking' => 'બુકિંગ બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    public function bookingSuccess(Request $request): View
    {
        $paymentId = $request->query('payment_id');
        $orderId = $request->query('order_id');
        $signature = $request->query('signature');

        $verified = false;
        $booking = null;

        if ($paymentId && $orderId && $signature) {
            $razorpayService = app(RazorpayService::class);
            $verified = $razorpayService->verifyPaymentSignature($orderId, $paymentId, $signature);

            if ($verified) {
                $payment = Payment::where('razorpay_order_id', $orderId)->first();
                if ($payment) {
                    // Single-source-of-truth capture path — flips
                    // payment + booking status, dispatches the
                    // GenerateHallInvoice job (PDF + email), and runs
                    // the hall.booking.confirmed notification. Web
                    // used to do a partial capture here and call
                    // generateHallInvoice() inline; both now route
                    // through markCaptured so API + web are identical.
                    app(PaymentCaptureService::class)->markCaptured(
                        $payment,
                        $paymentId,
                    );

                    $booking = HallBooking::where('payment_id', $payment->id)
                        ->with('hall')
                        ->first();
                }
            }
        }

        return view('pages.hall-booking.success', compact('verified', 'booking'));
    }

    public function bookingFailure(): View
    {
        return view('pages.hall-booking.failure');
    }

    public function downloadInvoice(HallBooking $booking)
    {
        $devotee = Auth::guard('devotee')->user();
        if (! $devotee || $booking->devotee_id !== $devotee->id) {
            abort(403);
        }
        // 'completed' too — past bookings keep their invoice downloadable.
        if (! in_array($booking->status, ['confirmed', 'completed'], true)) {
            abort(404, 'ઇનવૉઇસ બુકિંગ કન્ફર્મ થયા પછી જ ઉપલબ્ધ થશે.');
        }

        // No R2 ->exists() probe — S3 HEADs from Hostinger hang, and the
        // sweep NULLs invoice_path when it deletes the object.
        // needsRegeneration() also covers a stale-locale path.
        if (app(\App\Services\HallInvoiceService::class)->needsRegeneration($booking)) {
            try {
                // Service, not the job — self-heal regen must not
                // re-notify the customer.
                app(\App\Services\HallInvoiceService::class)->generateInvoice($booking);
                $booking->refresh();
            } catch (\Throwable $e) {
                Log::error('On-demand hall invoice regen failed', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
            if (! $booking->invoice_path) {
                abort(404, 'ઇનવૉઇસ બનાવી શકાયો નથી. કૃપા કરી થોડી વાર પછી પ્રયાસ કરો.');
            }
        }

        // Redirect to a presigned R2 URL instead of proxying bytes through PHP.
        $filename = "Hall_Booking_{$booking->id}.pdf";

        return private_file_redirect($booking->invoice_path, $filename);
    }

    // PDF render + hall.booking.confirmed dispatch both live in
    // GenerateHallInvoice (job) / HallInvoiceService now — the old
    // generateHallInvoice() + emailHallInvoice() duplicates were
    // removed 2026-08-04 when the receipt merged into the trigger.

    /**
     * Devotee asks the trust to cancel a confirmed booking (2026-08-12).
     *
     * Website twin of POST /api/v1/hall-bookings/{booking}/cancel-request.
     * Both go through HallCancellationService so the eligibility rule is
     * defined once. A REQUEST only — nothing is cancelled here.
     */
    public function requestCancellation(Request $request, HallBooking $booking, \App\Services\HallCancellationService $cancellations)
    {
        $devotee = Auth::guard('devotee')->user();

        // 404 rather than 403 so the URL cannot be used to discover which
        // booking ids exist against other devotees.
        abort_if($booking->devotee_id !== $devotee->id, 404);

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reasonCode = $cancellations->ineligibilityReason($booking);
        if ($reasonCode !== null) {
            return back()->withErrors(['cancel' => __('halls.cancel_blocked_'.$reasonCode)]);
        }

        if (! $cancellations->request($booking, $validated['reason'] ?? null)) {
            return back()->withErrors(['cancel' => __('halls.cancel_blocked_already_requested')]);
        }

        return back()->with('success', __('halls.cancel_requested_ok'));
    }
}
