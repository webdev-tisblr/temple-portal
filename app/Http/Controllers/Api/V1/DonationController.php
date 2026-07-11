<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\CreateDonationRequest;
use App\Http\Resources\DonationResource;
use App\Models\Donation;
use App\Models\DonationType;
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

        // Process extra_data image uploads — mirrors DonationWebController.
        // The app sends image extra-fields as multipart (extra_data[key] =
        // file); store each to R2 and replace the value with the R2 path so
        // GreetingCardService can use it as an overlay source. Text fields
        // pass through untouched.
        $extraData = $validated['extra_data'] ?? null;
        if ($extraData && ! empty($validated['donation_type_id'])) {
            $donationType = DonationType::find($validated['donation_type_id']);
            if ($donationType && is_array($donationType->extra_fields)) {
                foreach ($donationType->extra_fields as $field) {
                    $key = $field['key'] ?? null;
                    if ($key && ($field['type'] ?? '') === 'image' && $request->hasFile("extra_data.{$key}")) {
                        $extraData[$key] = $request->file("extra_data.{$key}")->store('donation-extras', 'r2');
                    }
                }
            }
        }

        try {
            $result = DB::transaction(function () use ($validated, $devotee, $amount, $fy, $extraData) {
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
                    'sub_cause_id' => $validated['sub_cause_id'] ?? null,
                    'is_80g_eligible' => true,
                    'anonymous' => $validated['anonymous'] ?? false,
                    'extra_data' => $extraData,
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

    public function downloadReceipt(Request $request, Donation $donation): JsonResponse|\Symfony\Component\HttpFoundation\Response
    {
        if ($donation->devotee_id !== $request->user()->id) {
            return $this->error('Unauthorized', 403);
        }

        // Only donations whose payment was captured can have a receipt.
        $paymentStatus = $donation->payment?->status?->value ?? null;
        if ($paymentStatus !== 'captured') {
            return $this->error('Receipt is generated only after the payment is confirmed.', 404);
        }

        // Self-heal: if the receipt was never generated OR the PDF on R2 is
        // gone, regenerate JUST the PDF via the service. Do NOT dispatch the
        // Generate80GReceipt job here — that path also emails + WhatsApps the
        // donor, which should only happen once on initial confirmation.
        $needsRegen = ! $donation->receipt_generated
            || ! $donation->receipt
            || ! $donation->receipt->pdf_path
            || ! Storage::disk('r2_private')->exists($donation->receipt->pdf_path);

        if ($needsRegen) {
            try {
                app(\App\Services\ReceiptService::class)->generateReceipt($donation->fresh());
                // refresh() reloads attributes; load() forces the receipt
                // relation to re-query so we see the just-created row.
                $donation->refresh()->load('receipt');
            } catch (\Throwable $e) {
                Log::error('On-demand 80G receipt regeneration failed', [
                    'donation_id' => $donation->id,
                    'error' => $e->getMessage(),
                ]);
                return $this->error('Receipt generation failed. Please try again or contact support.', 500);
            }
        }

        $receipt = $donation->receipt;
        if (! $receipt || ! $receipt->pdf_path || ! Storage::disk('r2_private')->exists($receipt->pdf_path)) {
            return $this->error('Receipt file could not be regenerated.', 500);
        }

        // Redirect to a short-lived presigned R2 URL so the PDF streams
        // straight from storage instead of being pulled into PHP and re-sent.
        // dio follows the 302; the presign carries its own auth in the query
        // string, and ResponseContentDisposition sets the download filename.
        $filename = 'receipt-' . str_replace('/', '-', $receipt->receipt_number) . '.pdf';

        return private_file_redirect($receipt->pdf_path, $filename);
    }
}
