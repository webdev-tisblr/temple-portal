<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookSevaRequest;
use App\Jobs\Generate80GReceipt;
use App\Models\Donation;
use App\Models\Payment;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\RazorpayService;
use App\Services\SevaSlotService;
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

        $linkedProducts = $seva->hasProductSelection() ? $seva->getLinkedProductsList() : collect();

        return view('pages.seva.show', compact('seva', 'linkedProducts'));
    }

    public function book(BookSevaRequest $request, Seva $seva): View|RedirectResponse
    {
        $validated = $request->validated();
        $devotee = Auth::guard('devotee')->user();
        $quantity = (int) ($validated['quantity'] ?? 1);
        $totalAmount = (float) $seva->price * $quantity;

        // Validate slot via service (acceptance period, blackout, capacity)
        $slotError = app(SevaSlotService::class)->validateBooking($seva, $validated['booking_date'], $validated['slot_time'] ?? null);
        if ($slotError) {
            return back()->withErrors(['slot_time' => $slotError]);
        }

        // TEST MODE — skip Razorpay, direct confirm
        if (config('razorpay.test_mode')) {
            return $this->bookTestMode($seva, $validated, $devotee, $quantity, $totalAmount);
        }

        // REAL PAYMENT MODE
        try {
            $result = DB::transaction(function () use ($seva, $validated, $devotee, $quantity, $totalAmount) {
                $paymentId = (string) Str::uuid();
                $receipt = 'SEVA-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $razorpayService = app(RazorpayService::class);
                $amountInPaise = (int) round($totalAmount * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'seva_id' => $seva->id,
                    'booking_date' => $validated['booking_date'],
                ]);

                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => $razorpayOrder->id,
                    'amount' => $totalAmount,
                    'currency' => 'INR',
                    'status' => 'created',
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
                    'status' => 'pending',
                    'payment_id' => $payment->id,
                    'devotee_name_for_seva' => $validated['devotee_name_for_seva'] ?? $devotee->name,
                    'gotra' => $validated['gotra'] ?? null,
                    'sankalp' => $validated['sankalp'] ?? null,
                    'selected_product_id' => $validated['selected_product_id'] ?? null,
                ]);

                return [
                    'booking' => $booking,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                ];
            });

            return view('pages.seva.checkout', [
                'razorpayKeyId' => \App\Models\SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'orderId' => $result['razorpay_order']->id,
                'amount' => (int) round($totalAmount * 100),
                'currency' => 'INR',
                'description' => $seva->name_en . ' - ' . $validated['booking_date'],
                'devoteeName' => $devotee->name,
                'devoteePhone' => $devotee->phone,
                'devoteeEmail' => $devotee->email ?? '',
            ]);

        } catch (\Exception $e) {
            Log::error('Web seva booking failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['booking' => 'બુકિંગ બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    private function bookTestMode(Seva $seva, array $validated, $devotee, int $quantity, float $totalAmount): View|RedirectResponse
    {
        try {
            $result = DB::transaction(function () use ($seva, $validated, $devotee, $quantity, $totalAmount) {
                $paymentId = (string) Str::uuid();

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
                    'selected_product_id' => $validated['selected_product_id'] ?? null,
                ]);

                // Create donation record for 80G receipt
                $fy = now()->month >= 4
                    ? now()->year . '-' . substr((string) (now()->year + 1), -2)
                    : (now()->year - 1) . '-' . substr((string) now()->year, -2);

                $donation = Donation::create([
                    'id' => (string) Str::uuid(),
                    'devotee_id' => $devotee->id,
                    'payment_id' => $payment->id,
                    'amount' => $totalAmount,
                    'donation_type' => 'seva',
                    'purpose' => 'Seva: ' . ($seva->name_en ?? 'Seva Booking'),
                    'seva_booking_id' => $booking->id,
                    'is_80g_eligible' => true,
                    'financial_year' => $fy,
                ]);

                return ['booking' => $booking, 'payment' => $payment, 'donation' => $donation];
            });

            Log::info('Web seva booking confirmed (test mode)', ['booking_id' => $result['booking']->id]);

            // Auto-generate 80G receipt
            if ($result['donation']) {
                Generate80GReceipt::dispatchSync($result['donation']);
            }

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
                    $payment->update([
                        'status' => 'captured',
                        'razorpay_payment_id' => $paymentId,
                        'paid_at' => $payment->paid_at ?? now(),
                    ]);

                    $booking = SevaBooking::where('payment_id', $payment->id)->with('seva.assignee', 'selectedProduct')->first();

                    // Confirm the booking after payment verification
                    if ($booking && $booking->status !== 'confirmed') {
                        $booking->update(['status' => 'confirmed']);
                    }

                    // Create donation record if not yet created (webhook may have done it already)
                    $donation = Donation::where('payment_id', $payment->id)->first();
                    if (! $donation && $booking) {
                        $fy = now()->month >= 4
                            ? now()->year . '-' . substr((string) (now()->year + 1), -2)
                            : (now()->year - 1) . '-' . substr((string) now()->year, -2);

                        $donation = Donation::create([
                            'id' => (string) Str::uuid(),
                            'devotee_id' => $booking->devotee_id,
                            'payment_id' => $payment->id,
                            'amount' => $payment->amount,
                            'donation_type' => 'seva',
                            'purpose' => 'Seva: ' . ($booking->seva->name_en ?? 'Seva Booking'),
                            'seva_booking_id' => $booking->id,
                            'is_80g_eligible' => true,
                            'financial_year' => $fy,
                        ]);
                    }

                    // Auto-generate 80G receipt
                    if ($donation && ! $donation->receipt_generated) {
                        Generate80GReceipt::dispatchSync($donation);
                    }
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
