<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateHallInvoice;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\PaymentCaptureService;
use App\Services\RazorpayService;
use Artesaos\SEOTools\Facades\SEOMeta;
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

    public function checkAvailability(Request $request): JsonResponse
    {
        // Full-day only (2026-08-04): the half-day option was removed.
        // `booking_type`/`type` params are still accepted-and-ignored so
        // older cached pages don't 422.
        $request->validate([
            'hall_id' => ['required', 'integer', 'exists:temple_halls,id'],
            'date' => ['required', 'date'],
        ]);

        // Admin blockout wins over everything (maintenance/trust events).
        $hall = Hall::find($request->integer('hall_id'));
        $blackoutReason = $hall?->blackoutReason((string) $request->date);
        if ($blackoutReason !== null) {
            return response()->json([
                'available' => false,
                'message' => $blackoutReason !== ''
                    ? $blackoutReason
                    : 'આ તારીખે હૉલ બુકિંગ ઉપલબ્ધ નથી.',
            ]);
        }

        if ($this->hallDateBooked($request->integer('hall_id'), (string) $request->date)) {
            return response()->json([
                'available' => false,
                'message' => 'આ તારીખ માટે હૉલ પહેલેથી બુક છે.',
            ]);
        }

        return response()->json([
            'available' => true,
            'message' => 'હૉલ ઉપલબ્ધ છે.',
        ]);
    }

    /**
     * True when the hall already has ANY pending or confirmed booking on
     * the date. Bookings are full-day only, so one booking blocks the
     * whole date (legacy half-day rows block it too).
     */
    private function hallDateBooked(int $hallId, string $date): bool
    {
        return HallBooking::where('hall_id', $hallId)
            ->where('booking_date', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();
    }

    public function book(Request $request): View
    {
        $validated = $request->validate([
            'hall_id' => ['required', 'integer', 'exists:temple_halls,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
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

        $blackoutReason = $hall->blackoutReason($validated['booking_date']);
        if ($blackoutReason !== null) {
            return back()->withErrors(['booking_date' => $blackoutReason !== ''
                ? $blackoutReason
                : 'આ તારીખે હૉલ બુકિંગ ઉપલબ્ધ નથી.']);
        }

        if ($this->hallDateBooked((int) $hall->id, $validated['booking_date'])) {
            return back()->withErrors(['booking_date' => 'આ તારીખ માટે હૉલ પહેલેથી બુક છે.']);
        }

        $totalAmount = (float) $hall->price_per_day;

        // TEST MODE — skip Razorpay, direct confirm
        if (config('razorpay.test_mode')) {
            return $this->bookTestMode($validated, $devotee, $hall, $totalAmount);
        }

        // REAL PAYMENT MODE
        try {
            $result = DB::transaction(function () use ($validated, $devotee, $hall, $totalAmount) {
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
                    'booking_type' => $validated['booking_type'],
                    'purpose' => $validated['purpose'],
                    'expected_guests' => $validated['expected_guests'] ?? null,
                    'contact_name' => $validated['contact_name'],
                    'contact_phone' => $validated['contact_phone'],
                    'total_amount' => $totalAmount,
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

        } catch (\Exception $e) {
            Log::error('Hall booking failed', ['error' => $e->getMessage()]);

            return back()->withErrors(['booking' => 'બુકિંગ બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    private function bookTestMode(array $validated, $devotee, Hall $hall, float $totalAmount): View
    {
        try {
            $result = DB::transaction(function () use ($validated, $devotee, $hall, $totalAmount) {
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
                    'booking_type' => $validated['booking_type'],
                    'purpose' => $validated['purpose'],
                    'expected_guests' => $validated['expected_guests'] ?? null,
                    'contact_name' => $validated['contact_name'],
                    'contact_phone' => $validated['contact_phone'],
                    'total_amount' => $totalAmount,
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
        if (! $booking->invoice_path) {
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
}
