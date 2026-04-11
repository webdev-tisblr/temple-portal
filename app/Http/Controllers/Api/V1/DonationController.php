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

                // TODO: Replace test mode with Razorpay when keys are configured
                $payment = Payment::create([
                    'id' => $paymentId,
                    'razorpay_order_id' => 'test_' . Str::random(14),
                    'amount' => $amount,
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => 'test',
                    'paid_at' => now(),
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

                return ['donation' => $donation, 'payment' => $payment];
            });

            Log::info('Donation confirmed (test mode)', [
                'donation_id' => $result['donation']->id,
                'amount' => $amount,
            ]);

            return $this->success([
                'donation_id' => $result['donation']->id,
                'payment_id' => $result['payment']->id,
                'amount' => $amount,
                'status' => 'confirmed',
                'message' => 'દાન સફળ! (Test mode — Razorpay pending)',
            ], 'દાન સફળતાપૂર્વક નોંધાયું.');

        } catch (\Exception $e) {
            Log::error('Donation creation failed', ['error' => $e->getMessage()]);
            return $this->error('દાન બનાવવામાં નિષ્ફળ. ફરી પ્રયાસ કરો.', 500);
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
