<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateDonationRequest;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\Payment;
use App\Services\RazorpayService;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class DonationWebController extends Controller
{
    public function index(): View
    {
        $campaigns = DonationCampaign::where('is_active', true)
            ->where('start_date', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now());
            })
            ->get();

        SEOMeta::setTitle('દાન કરો — શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતળિયા હનુમાનજી મંદિર માટે ઓનલાઈન દાન કરો.');

        return view('pages.donation.index', compact('campaigns'));
    }

    public function create(CreateDonationRequest $request): View
    {
        $validated = $request->validated();
        $devotee = Auth::guard('devotee')->user();
        $amount = (float) $validated['amount'];

        $fy = now()->month >= 4
            ? now()->year . '-' . substr((string) (now()->year + 1), -2)
            : (now()->year - 1) . '-' . substr((string) now()->year, -2);

        try {
            $result = DB::transaction(function () use ($validated, $devotee, $amount, $fy) {
                $paymentId = (string) Str::uuid();
                $receipt = 'DON-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $razorpayService = app(RazorpayService::class);
                $amountInPaise = (int) round($amount * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'donation_type' => $validated['donation_type'],
                ]);

                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => $razorpayOrder->id,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'status' => 'created',
                    'description' => "Donation - {$validated['donation_type']}",
                ]);

                $donation = Donation::create([
                    'id' => (string) Str::uuid(),
                    'devotee_id' => $devotee->id,
                    'payment_id' => $payment->id,
                    'amount' => $amount,
                    'donation_type' => $validated['donation_type'],
                    'purpose' => $validated['purpose'] ?? null,
                    'campaign_id' => $validated['campaign_id'] ?? null,
                    'is_80g_eligible' => true,
                    'pan_verified' => !empty($devotee->pan_encrypted),
                    'pan_number_encrypted' => $devotee->pan_encrypted,
                    'anonymous' => $validated['anonymous'] ?? false,
                    'financial_year' => $fy,
                ]);

                return [
                    'donation' => $donation,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                ];
            });

            return view('pages.seva.checkout', [
                'seva' => null,
                'booking' => null,
                'razorpayKeyId' => config('razorpay.key_id'),
                'orderId' => $result['razorpay_order']->id,
                'amount' => (int) round($amount * 100),
                'currency' => 'INR',
                'description' => 'દાન — ' . ucfirst($validated['donation_type']),
                'devoteeName' => $devotee->name,
                'devoteePhone' => $devotee->phone,
                'devoteeEmail' => $devotee->email ?? '',
            ]);

        } catch (\Exception $e) {
            Log::error('Web donation failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['donation' => 'દાન બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    public function thankYou(Request $request): View
    {
        $paymentId = $request->query('payment_id');
        $orderId = $request->query('order_id');
        $signature = $request->query('signature');

        $verified = false;
        $donation = null;

        if ($paymentId && $orderId && $signature) {
            $razorpayService = app(RazorpayService::class);
            $verified = $razorpayService->verifyPaymentSignature($orderId, $paymentId, $signature);

            if ($verified) {
                $payment = Payment::where('razorpay_order_id', $orderId)->first();
                if ($payment) {
                    $donation = Donation::where('payment_id', $payment->id)->with('receipt')->first();
                }
            }
        }

        return view('pages.donation.thank-you', compact('verified', 'donation'));
    }
}
