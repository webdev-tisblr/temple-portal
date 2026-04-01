<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookSevaRequest;
use App\Models\Payment;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\RazorpayService;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SevaWebController extends Controller
{
    public function index(): View
    {
        $sevas = Cache::remember('active_sevas', 600, function () {
            return Seva::where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        });

        $grouped = $sevas->groupBy(fn ($seva) => $seva->getRawOriginal('category'));

        SEOMeta::setTitle('સેવા અને પૂજા — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતળિયા હનુમાનજી મંદિરમાં સેવા અને પૂજા ઓનલાઈન બુક કરો.');

        return view('pages.seva.index', compact('sevas', 'grouped'));
    }

    public function show(Seva $seva): View
    {
        SEOMeta::setTitle("{$seva->name} — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ");
        SEOMeta::setDescription($seva->description ?? '');

        return view('pages.seva.show', compact('seva'));
    }

    public function book(BookSevaRequest $request, Seva $seva): View|RedirectResponse
    {
        $validated = $request->validated();
        $devotee = Auth::guard('devotee')->user();
        $quantity = $validated['quantity'] ?? 1;
        $totalAmount = (float) $seva->price * $quantity;

        if ($seva->requires_booking && !empty($validated['slot_time'])) {
            $slotTaken = SevaBooking::where('seva_id', $seva->id)
                ->where('booking_date', $validated['booking_date'])
                ->where('slot_time', $validated['slot_time'])
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->exists();

            if ($slotTaken) {
                return back()->withErrors(['slot_time' => 'આ સ્લોટ પહેલેથી બુક થયેલ છે. કૃપા કરીને બીજો પસંદ કરો.']);
            }
        }

        try {
            $result = DB::transaction(function () use ($seva, $validated, $devotee, $quantity, $totalAmount) {
                $paymentId = (string) Str::uuid();

                // Create payment record (skip Razorpay for now — direct confirm)
                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => 'test_' . Str::random(14),
                    'amount' => $totalAmount,
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => 'test',
                    'paid_at' => now(),
                    'description' => "{$seva->name_en} - {$validated['booking_date']}",
                ]);

                $booking = SevaBooking::create([
                    'id' => (string) Str::uuid(),
                    'devotee_id' => $devotee->id,
                    'seva_id' => $seva->id,
                    'booking_date' => $validated['booking_date'],
                    'slot_time' => $validated['slot_time'] ?? null,
                    'quantity' => $quantity,
                    'total_amount' => $totalAmount,
                    'status' => 'confirmed',
                    'payment_id' => $payment->id,
                    'devotee_name_for_seva' => $validated['devotee_name_for_seva'] ?? $devotee->name,
                    'gotra' => $validated['gotra'] ?? null,
                    'sankalp' => $validated['sankalp'] ?? null,
                ]);

                return ['booking' => $booking, 'payment' => $payment];
            });

            Log::info('Web seva booking confirmed (test mode)', [
                'booking_id' => $result['booking']->id,
            ]);

            // Skip Razorpay checkout — go directly to success
            return view('pages.seva.booking-success', [
                'verified' => true,
                'booking' => $result['booking']->load('seva'),
            ]);

        } catch (\Exception $e) {
            Log::error('Web seva booking failed', ['error' => $e->getMessage()]);
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
                    $booking = SevaBooking::where('payment_id', $payment->id)->with('seva')->first();
                }
            }
        }

        return view('pages.seva.booking-success', compact('verified', 'booking'));
    }

    public function bookingFailure(): View
    {
        return view('pages.seva.booking-failure');
    }
}
