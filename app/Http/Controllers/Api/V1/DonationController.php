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
                    'donation_type_id' => $validated['donation_type_id'] ?? null,
                    'purpose' => $validated['purpose'] ?? null,
                    'campaign_id' => $validated['campaign_id'] ?? null,
                    'is_80g_eligible' => true,
                    'pan_verified' => !empty($devotee->pan_encrypted),
                    'pan_number_encrypted' => $devotee->pan_encrypted,
                    'anonymous' => $validated['anonymous'] ?? false,
                    'extra_data' => $validated['extra_data'] ?? null,
                    'financial_year' => $fy,
                ]);

                return [
                    'donation' => $donation,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                ];
            });

            Log::info('Donation created, awaiting payment', [
                'donation_id' => $result['donation']->id,
                'razorpay_order_id' => $result['razorpay_order']->id,
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
                'description' => 'દાન — ' . ucfirst($validated['donation_type']),
            ], 'Donation created. Complete payment.');

        } catch (\Exception $e) {
            Log::error('Donation creation failed', ['error' => $e->getMessage()]);
            return $this->error('દાન બનાવવામાં નિષ્ફળ. ફરી પ્રયાસ કરો.', 500);
        }
    }

    public function history(Request $request): JsonResponse
    {
        $donations = Donation::where('devotee_id', $request->user()->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->with(['receipt', 'payment'])
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $donations->getCollection()
            ->map(fn (Donation $d) => (new DonationResource($d))->toArray($request))
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
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

        // Only donations whose payment was captured can have a receipt.
        $paymentStatus = $donation->payment?->status?->value ?? null;
        if ($paymentStatus !== 'captured') {
            return $this->error('Receipt is generated only after the payment is confirmed.', 404);
        }

        // Self-heal: if the receipt was never generated (queue didn't run, etc),
        // generate it now synchronously so the user gets the PDF on this click.
        if (! $donation->receipt_generated || ! $donation->receipt) {
            try {
                \App\Jobs\Generate80GReceipt::dispatchSync($donation->fresh());
                $donation->refresh();
            } catch (\Throwable $e) {
                Log::error("On-demand 80G receipt generation failed", [
                    'donation_id' => $donation->id,
                    'error' => $e->getMessage(),
                ]);
                return $this->error('Receipt generation failed. Please try again or contact support.', 500);
            }
        }

        $receipt = $donation->receipt;
        if (! $receipt || ! $receipt->pdf_path) {
            return $this->error('Receipt PDF not available', 404);
        }

        if (! Storage::disk('r2_private')->exists($receipt->pdf_path)) {
            // PDF path in DB but R2 object is gone — regenerate.
            try {
                \App\Jobs\Generate80GReceipt::dispatchSync($donation->fresh());
                $donation->refresh();
                $receipt = $donation->receipt;
            } catch (\Throwable $e) {
                Log::error("Receipt regeneration failed", [
                    'donation_id' => $donation->id,
                    'error' => $e->getMessage(),
                ]);
            }
            if (! $receipt || ! $receipt->pdf_path || ! Storage::disk('r2_private')->exists($receipt->pdf_path)) {
                return $this->error('Receipt file could not be regenerated.', 500);
            }
        }

        return Storage::disk('r2_private')->download(
            $receipt->pdf_path,
            "receipt-{$receipt->receipt_number}.pdf",
            ['Content-Type' => 'application/pdf'],
        );
    }
}
