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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class HallBookingController extends Controller
{
    public function index(): View
    {
        $hall = Hall::where('is_active', true)->first();

        SEOMeta::setTitle('હૉલ બુકિંગ — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતળિયા હનુમાનજી મંદિર હૉલ ઓનલાઈન બુક કરો.');

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

    private function generateHallInvoice(HallBooking $booking): void
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

            Storage::disk('local')->makeDirectory($directory);
            Storage::disk('local')->put($path, $pdf->output());

            $booking->update(['invoice_path' => $path]);

            // Email invoice if contact_email is present
            if ($booking->contact_email) {
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
            $pdfPath = Storage::disk('local')->path($path);
            $bookingNumber = 'HALL-' . $booking->id . '-' . $booking->created_at->format('Ymd');
            $subject = "Hall Booking Confirmation — {$bookingNumber}";

            $html = $this->buildHallInvoiceEmailHtml($booking, $bookingNumber);

            Mail::html($html, function ($message) use ($booking, $pdfPath, $bookingNumber, $subject) {
                $message->to($booking->contact_email, $booking->contact_name)
                    ->subject($subject)
                    ->attach($pdfPath, [
                        'as' => "HallBooking_{$bookingNumber}.pdf",
                        'mime' => 'application/pdf',
                    ]);
            });

            Log::info('Hall booking invoice emailed', [
                'booking_id' => $booking->id,
                'email' => $booking->contact_email,
            ]);
        } catch (\Exception $e) {
            Log::error('Hall invoice email failed', [
                'booking_id' => $booking->id,
                'email' => $booking->contact_email ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildHallInvoiceEmailHtml(HallBooking $booking, string $bookingNumber): string
    {
        $contactName = e($booking->contact_name);
        $hallName = e($booking->hall->name ?? 'Hall');
        $bookingDate = $booking->booking_date->format('d M Y');
        $bookingType = match ($booking->booking_type) {
            'full_day' => 'Full Day',
            'half_day_morning' => 'Half Day (Morning)',
            'half_day_evening' => 'Half Day (Evening)',
            default => ucfirst($booking->booking_type),
        };
        $purpose = e($booking->purpose);
        $total = number_format((float) $booking->total_amount, 2);

        return <<<HTML
        <div style="font-family:'Segoe UI',Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;">
            <div style="background:#881337;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
                <h1 style="color:#e8c36a;margin:0;font-size:20px;">Hall Booking Confirmed!</h1>
                <p style="color:#ddd;margin:6px 0 0;font-size:13px;">Shree Pataliya Hanumanji Seva Trust</p>
            </div>

            <div style="padding:24px;background:#fff;border:1px solid #eee;border-top:none;">
                <p style="margin:0 0 16px;">Dear <strong>{$contactName}</strong>,</p>
                <p style="margin:0 0 20px;color:#555;">Your hall booking has been confirmed. Here are the details:</p>

                <table style="width:100%;border-collapse:collapse;margin-bottom:16px;background:#f9f5ef;border-radius:6px;overflow:hidden;">
                    <tr>
                        <td style="padding:10px 14px;color:#888;font-size:12px;">Booking No.</td>
                        <td style="padding:10px 14px;font-weight:700;color:#881337;">{$bookingNumber}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 14px;color:#888;font-size:12px;">Hall</td>
                        <td style="padding:10px 14px;font-weight:600;">{$hallName}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 14px;color:#888;font-size:12px;">Date</td>
                        <td style="padding:10px 14px;font-weight:600;">{$bookingDate}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 14px;color:#888;font-size:12px;">Type</td>
                        <td style="padding:10px 14px;font-weight:600;">{$bookingType}</td>
                    </tr>
                    <tr>
                        <td style="padding:10px 14px;color:#888;font-size:12px;">Purpose</td>
                        <td style="padding:10px 14px;font-weight:600;">{$purpose}</td>
                    </tr>
                    <tr style="background:#f0e8d8;">
                        <td style="padding:12px 14px;font-weight:700;font-size:14px;color:#881337;">Total</td>
                        <td style="padding:12px 14px;font-weight:700;font-size:14px;color:#881337;">₹{$total}</td>
                    </tr>
                </table>

                <p style="margin:20px 0 0;color:#555;font-size:13px;">Your invoice is attached to this email as a PDF.</p>
                <p style="margin:16px 0 0;color:#881337;font-weight:600;">May Shree Hanumanji bless you and your family.</p>
            </div>

            <div style="padding:16px;text-align:center;background:#f5f0ea;border-radius:0 0 8px 8px;border:1px solid #eee;border-top:none;">
                <p style="margin:0;font-size:11px;color:#999;">Shree Pataliya Hanumanji Seva Trust</p>
                <p style="margin:2px 0 0;font-size:11px;color:#bbb;">Antarjal, Gandhidham, Kutch - Gujarat</p>
            </div>
        </div>
        HTML;
    }
}
