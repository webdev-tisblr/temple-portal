<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CreateDonationRequest;
use App\Http\Resources\DonationResource;
use App\Models\Donation;
use App\Models\Payment;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DonationController extends BaseApiController
{
    public function create(CreateDonationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $devotee = $request->user();
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

            Log::info('Donation created', [
                'donation_id' => $result['donation']->id,
                'amount' => $amount,
            ]);

            return $this->success([
                'donation_id' => $result['donation']->id,
                'payment_id' => $result['payment']->id,
                'razorpay_order_id' => $result['razorpay_order']->id,
                'razorpay_key_id' => \App\Models\SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'amount' => (int) round($amount * 100),
                'currency' => 'INR',
                'devotee_name' => $devotee->name,
                'devotee_phone' => $devotee->phone,
                'devotee_email' => $devotee->email,
                'description' => "Donation - " . ucfirst($result['donation']->donation_type->value),
            ], 'Donation created. Complete payment to confirm.');

        } catch (\Exception $e) {
            Log::error('Donation creation failed', ['error' => $e->getMessage()]);
            return $this->error('Failed to create donation. Please try again.', 500);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $donations = Donation::where('devotee_id', $request->user()->id)
            ->with('receipt')
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => DonationResource::collection($donations),
            'meta' => [
                'current_page' => $donations->currentPage(),
                'last_page' => $donations->lastPage(),
                'total' => $donations->total(),
            ],
        ]);
    }

    public function show(Request $request, Donation $donation): JsonResponse
    {
        if ($donation->devotee_id !== $request->user()->id) {
            return $this->error('Unauthorized', 403);
        }

        $donation->load('receipt');

        return $this->success(new DonationResource($donation));
    }

    public function downloadReceipt(Request $request, Donation $donation): JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        if ($donation->devotee_id !== $request->user()->id) {
            return $this->error('Unauthorized', 403);
        }

        if (!$donation->receipt_generated) {
            return $this->error('Receipt not yet generated', 404);
        }

        $receipt = $donation->receipt;
        if (!$receipt || !$receipt->pdf_path) {
            return $this->error('Receipt PDF not available', 404);
        }

        $fullPath = Storage::disk('local')->path($receipt->pdf_path);

        if (!file_exists($fullPath)) {
            return $this->error('Receipt file not found', 404);
        }

        return response()->download($fullPath, "receipt-{$receipt->receipt_number}.pdf", [
            'Content-Type' => 'application/pdf',
        ]);
    }
}
