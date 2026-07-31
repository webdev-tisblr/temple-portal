<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Helpers\NumberToWords;
use App\Http\Controllers\Controller;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use App\Services\PaymentCaptureService;
use App\Services\RazorpayService;
use App\Support\Pdf\GujaratiPdf;
use Artesaos\SEOTools\Facades\SEOMeta;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        // Accept both `type` (front-end JS) and `booking_type` (canonical)
        // for backward compatibility. The Alpine handler in
        // pages/hall-booking/index.blade.php sends `type=...` — when only
        // `booking_type` was honoured the controller silently defaulted
        // to 'full_day' and every half-day check returned "available" even
        // after a confirmed booking on the same date / slot.
        $request->validate([
            'hall_id' => ['required', 'integer', 'exists:temple_halls,id'],
            'date' => ['required', 'date'],
            'booking_type' => ['nullable', 'string', 'in:full_day,half_day_morning,half_day_evening'],
            'type' => ['nullable', 'string', 'in:full_day,half_day_morning,half_day_evening'],
        ]);

        $bookingType = $request->input('booking_type')
            ?? $request->input('type', 'full_day');

        if ($this->hallSlotConflicts($request->integer('hall_id'), (string) $request->date, $bookingType)) {
            return response()->json([
                'available' => false,
                'message' => 'આ તારીખ અને સમય માટે હૉલ પહેલેથી બુક છે.',
            ]);
        }

        return response()->json([
            'available' => true,
            'message' => 'હૉલ ઉપલબ્ધ છે.',
        ]);
    }

    /**
     * True when the (hall, date, booking_type) combination overlaps an
     * existing pending or confirmed booking. Handles the full-day vs
     * half-day fan-out so a confirmed full_day blocks both half-days
     * and a confirmed half-day blocks any full_day attempt.
     */
    private function hallSlotConflicts(int $hallId, string $date, string $bookingType): bool
    {
        $query = HallBooking::where('hall_id', $hallId)
            ->where('booking_date', $date)
            ->whereIn('status', ['pending', 'confirmed']);

        if ($bookingType === 'full_day') {
            // Full-day attempt collides with ANY existing booking.
            return $query->exists();
        }

        // Half-day attempt collides with same-slot half-day OR a full_day.
        return $query->where(function ($q) use ($bookingType) {
            $q->where('booking_type', 'full_day')
                ->orWhere('booking_type', $bookingType);
        })->exists();
    }

    public function book(Request $request): View
    {
        $validated = $request->validate([
            'hall_id' => ['required', 'integer', 'exists:temple_halls,id'],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'booking_type' => ['required', 'string', 'in:full_day,half_day_morning,half_day_evening'],
            'purpose' => ['required', 'string', 'max:500'],
            'expected_guests' => ['nullable', 'integer'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:20'],
        ]);

        $devotee = Auth::guard('devotee')->user();
        $hall = Hall::where('id', $validated['hall_id'])->where('is_active', true)->firstOrFail();

        // Check availability — must use the overlap helper so that a
        // half_day_morning attempt is blocked by an existing full_day,
        // and a full_day attempt is blocked by either existing half-day.
        // Plain where('booking_type', $type) only matches exact type and
        // allowed a confirmed full_day booking to be silently overlapped
        // by half-day re-bookings on the same date.
        if ($this->hallSlotConflicts((int) $hall->id, $validated['booking_date'], $validated['booking_type'])) {
            return back()->withErrors(['booking_date' => 'આ તારીખ અને સમય માટે હૉલ પહેલેથી બુક છે.']);
        }

        // Calculate amount
        $isFullDay = $validated['booking_type'] === 'full_day';
        $totalAmount = $isFullDay ? (float) $hall->price_per_day : (float) $hall->price_per_half_day;

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

            // Generate invoice
            $this->generateHallInvoice($result['booking']);

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
                // false = self-heal regen, don't re-email the customer.
                $this->generateHallInvoice($booking, sendEmail: false);
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

    /**
     * Generate the hall booking invoice PDF + persist to R2.
     *
     * $sendEmail = true on initial confirmation flow (emails the customer
     *   their PDF). false when called from a self-heal download path so
     *   we don't email the customer every time they redownload the PDF.
     */
    public function generateHallInvoice(HallBooking $booking, bool $sendEmail = true): void
    {
        try {
            $booking->loadMissing('hall', 'devotee');

            $trustName = SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust');
            $trustAddress = SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205');

            $bookingNumber = 'HALL-'.$booking->id.'-'.$booking->created_at->format('Ymd');

            // mPDF via GujaratiPdf, NOT DomPDF — hirer names/addresses carry
            // Gujarati, which DomPDF cannot shape (matra/conjunct garbling).
            $output = GujaratiPdf::render('invoices.hall-booking-invoice', [
                'booking' => $booking,
                'trust_name' => $trustName,
                'trust_address' => $trustAddress,
                'booking_number' => $bookingNumber,
                'amount_in_words' => NumberToWords::convert((float) $booking->total_amount),
            ], ['format' => 'A4', 'watermark' => 'HALL BOOKING']);

            $directory = 'hall-invoices';
            $filename = "{$bookingNumber}.pdf";
            $path = "{$directory}/{$filename}";

            Storage::disk('r2_private')->put($path, $output);

            $booking->update(['invoice_path' => $path]);

            // Fire notification trigger only on the initial confirmation
            // path. Self-heal regeneration calls this with $sendEmail=false
            // to avoid re-notifying the customer every time the PDF is
            // re-downloaded. Notifications are dispatched via configured
            // NotificationTemplate rows (WhatsApp / SMS / push). Hall
            // booking no longer collects contact_email; the trust can
            // wire email via the devotee's profile email if needed.
            if ($sendEmail) {
                $this->emailHallInvoice($booking, $path);
            }

        } catch (\Exception $e) {
            Log::error('Hall invoice generation failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function emailHallInvoice(HallBooking $booking, string $path): void
    {
        try {
            $pdfBytes = Storage::disk('r2_private')->get($path);
            $bookingNumber = 'HALL-'.$booking->id.'-'.$booking->created_at->format('Ymd');

            $bookingTypeLabel = match ($booking->booking_type) {
                'full_day' => 'Full Day',
                'half_day_morning' => 'Half Day (Morning)',
                'half_day_evening' => 'Half Day (Evening)',
                default => ucfirst($booking->booking_type),
            };

            // Single dispatch entry — every enabled NotificationTemplate
            // for 'hall.booking.confirmed' (email today; WhatsApp / push
            // when the admin enables them) fires from here.
            app(NotificationService::class)->dispatch(
                'hall.booking.confirmed',
                [
                    'booking' => array_merge($booking->toArray(), [
                        'booking_number' => $bookingNumber,
                        'booking_type_label' => $bookingTypeLabel,
                        'booking_date' => $booking->booking_date?->format('d M Y'),
                        'total_amount_formatted' => number_format((float) $booking->total_amount, 2),
                        'contact_phone' => $booking->contact_phone,
                        'contact_name' => $booking->contact_name,
                        'hall' => $booking->hall ? $booking->hall->toArray() : null,
                    ]),
                    'devotee' => $booking->devotee,
                    'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
                    '_attachments' => [[
                        'data' => $pdfBytes,
                        'name' => "HallBooking_{$bookingNumber}.pdf",
                        'mime' => 'application/pdf',
                    ]],
                ],
                idempotencyKey: "hall-booking:{$booking->id}:confirmed",
            );

            Log::info('Hall booking invoice dispatched via NotificationService', [
                'booking_id' => $booking->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Hall invoice dispatch failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // The hall-booking confirmation email body now lives in the
    // notification_templates table (see NotificationTemplatesSeeder
    // for the seeded default). Removed: buildHallInvoiceEmailHtml().
}
