<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Helpers\NumberToWords;
use App\Http\Controllers\Controller;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\RazorpayService;
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

        SEOMeta::setTitle('હૉલ બુકિંગ — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતળિયા હનુમાનજી મંદિરના વિશાળ હોલ ઓનલાઈન બુક કરો.');

        return view('pages.hall-booking.list', compact('halls'));
    }

    /**
     * Per-hall page — gallery, amenities, pricing and the booking form.
     */
    public function hallShow(Hall $hall): View
    {
        abort_unless($hall->is_active, 404);

        SEOMeta::setTitle("{$hall->name} — હૉલ બુકિંગ — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ");
        SEOMeta::setDescription("શ્રી પાતળિયા હનુમાનજી મંદિર {$hall->name} ઓનલાઈન બુક કરો.");

        return view('pages.hall-booking.index', compact('hall'));
    }

    public function checkAvailability(Request $request): JsonResponse
    {
        $request->validate([
            'hall_id' => ['required', 'integer', 'exists:temple_halls,id'],
            'date' => ['required', 'date'],
            'booking_type' => ['nullable', 'string', 'in:full_day,half_day_morning,half_day_evening'],
        ]);

        $bookingType = $request->input('booking_type', 'full_day');

        $exists = HallBooking::where('hall_id', $request->hall_id)
            ->where('booking_date', $request->date)
            ->where('booking_type', $bookingType)
            ->whereIn('status', ['confirmed', 'pending'])
            ->exists();

        if ($exists) {
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
            'contact_email' => ['nullable', 'email'],
            'aadhaar_number' => ['required', 'string', 'size:12'],
            'contact_address' => ['required', 'string', 'max:500'],
        ]);

        $devotee = Auth::guard('devotee')->user();
        $hall = Hall::where('id', $validated['hall_id'])->where('is_active', true)->firstOrFail();

        // Check availability
        $exists = HallBooking::where('hall_id', $hall->id)
            ->where('booking_date', $validated['booking_date'])
            ->where('booking_type', $validated['booking_type'])
            ->whereIn('status', ['confirmed', 'pending'])
            ->exists();

        if ($exists) {
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
                $receipt = 'HALL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

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
                    'contact_email' => $validated['contact_email'] ?? null,
                    'aadhaar_number' => $validated['aadhaar_number'],
                    'contact_address' => $validated['contact_address'],
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
                    'razorpay_order_id' => 'test_' . Str::random(14),
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
                    'contact_email' => $validated['contact_email'] ?? null,
                    'aadhaar_number' => $validated['aadhaar_number'],
                    'contact_address' => $validated['contact_address'],
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
                    $payment->update([
                        'status' => 'captured',
                        'razorpay_payment_id' => $paymentId,
                        'paid_at' => $payment->paid_at ?? now(),
                    ]);

                    $booking = HallBooking::where('payment_id', $payment->id)->with('hall')->first();

                    if ($booking && $booking->status !== 'confirmed') {
                        $booking->update(['status' => 'confirmed']);
                    }

                    // Generate invoice
                    if ($booking) {
                        $this->generateHallInvoice($booking);
                    }
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
        $devotee = \Illuminate\Support\Facades\Auth::guard('devotee')->user();
        if (! $devotee || $booking->devotee_id !== $devotee->id) {
            abort(403);
        }
        if ($booking->status !== 'confirmed') {
            abort(404, 'ઇનવૉઇસ બુકિંગ કન્ફર્મ થયા પછી જ ઉપલબ્ધ થશે.');
        }

        if (! $booking->invoice_path || ! Storage::disk('r2_private')->exists($booking->invoice_path)) {
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
            if (! $booking->invoice_path || ! Storage::disk('r2_private')->exists($booking->invoice_path)) {
                abort(404, 'ઇનવૉઇસ બનાવી શકાયો નથી. કૃપા કરી થોડી વાર પછી પ્રયાસ કરો.');
            }
        }

        $pdfBytes = Storage::disk('r2_private')->get($booking->invoice_path);
        $filename = "Hall_Booking_{$booking->id}.pdf";

        return response($pdfBytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($pdfBytes),
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
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

            $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');
            $trustAddress = SystemSetting::getValue('trust_address', 'Antarjal, Gandhidham, Kutch - 370205');

            $bookingNumber = 'HALL-' . $booking->id . '-' . $booking->created_at->format('Ymd');

            $pdf = Pdf::loadView('invoices.hall-booking-invoice', [
                'booking' => $booking,
                'trust_name' => $trustName,
                'trust_address' => $trustAddress,
                'booking_number' => $bookingNumber,
                'amount_in_words' => NumberToWords::convert((float) $booking->total_amount),
            ]);
            $pdf->setPaper('a4');

            $directory = 'hall-invoices';
            $filename = "{$bookingNumber}.pdf";
            $path = "{$directory}/{$filename}";

            Storage::disk('r2_private')->put($path, $pdf->output());

            $booking->update(['invoice_path' => $path]);

            // Email invoice only on the initial confirmation path. Self-heal
            // regeneration calls this with $sendEmail=false to avoid emailing
            // the customer every time they redownload the PDF.
            if ($sendEmail && $booking->contact_email) {
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
            $bookingNumber = 'HALL-' . $booking->id . '-' . $booking->created_at->format('Ymd');

            $bookingTypeLabel = match ($booking->booking_type) {
                'full_day' => 'Full Day',
                'half_day_morning' => 'Half Day (Morning)',
                'half_day_evening' => 'Half Day (Evening)',
                default => ucfirst($booking->booking_type),
            };

            // Single dispatch entry — every enabled NotificationTemplate
            // for 'hall.booking.confirmed' (email today; WhatsApp / push
            // when the admin enables them) fires from here.
            app(\App\Services\Notifications\NotificationService::class)->dispatch(
                'hall.booking.confirmed',
                [
                    'booking' => array_merge($booking->toArray(), [
                        'booking_number' => $bookingNumber,
                        'booking_type_label' => $bookingTypeLabel,
                        'booking_date' => $booking->booking_date?->format('d M Y'),
                        'total_amount_formatted' => number_format((float) $booking->total_amount, 2),
                        'contact_email' => $booking->contact_email,
                        'contact_phone' => $booking->contact_phone,
                        'contact_name' => $booking->contact_name,
                        'hall' => $booking->hall ? $booking->hall->toArray() : null,
                    ]),
                    'devotee' => $booking->devotee,
                    'trust_name' => SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
                    '_attachments' => [[
                        'data' => $pdfBytes,
                        'name' => "HallBooking_{$bookingNumber}.pdf",
                        'mime' => 'application/pdf',
                    ]],
                ],
            );

            Log::info('Hall booking invoice dispatched via NotificationService', [
                'booking_id' => $booking->id,
                'email' => $booking->contact_email,
            ]);
        } catch (\Exception $e) {
            Log::error('Hall invoice dispatch failed', [
                'booking_id' => $booking->id,
                'email' => $booking->contact_email ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    // The hall-booking confirmation email body now lives in the
    // notification_templates table (see NotificationTemplatesSeeder
    // for the seeded default). Removed: buildHallInvoiceEmailHtml().
}
