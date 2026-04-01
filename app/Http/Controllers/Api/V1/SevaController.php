<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\BookSevaRequest;
use App\Http\Resources\SevaCollection;
use App\Http\Resources\SevaResource;
use App\Models\Payment;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SevaController extends BaseApiController
{
    public function index(Request $request): SevaCollection
    {
        $category = $request->query('category');

        $cacheKey = $category ? "active_sevas_{$category}" : 'active_sevas';

        $sevas = Cache::remember($cacheKey, 600, function () use ($category) {
            $query = Seva::where('is_active', true)->orderBy('sort_order');

            if ($category) {
                $query->where('category', $category);
            }

            return $query->paginate(20);
        });

        return new SevaCollection($sevas);
    }

    public function show(Seva $seva): SevaResource
    {
        return new SevaResource($seva);
    }

    public function availableSlots(Request $request, Seva $seva): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $date = $request->query('date');
        $slotConfig = $seva->slot_config;

        if (!$slotConfig || empty($slotConfig['time_slots'])) {
            return $this->success([
                'date' => $date,
                'slots' => [],
                'booked' => [],
            ]);
        }

        $allSlots = $slotConfig['time_slots'];

        $bookedSlots = SevaBooking::where('seva_id', $seva->id)
            ->where('booking_date', $date)
            ->whereNotIn('status', ['cancelled', 'refunded'])
            ->pluck('slot_time')
            ->map(fn ($time) => substr((string) $time, 0, 5))
            ->toArray();

        $availableSlots = array_values(array_diff($allSlots, $bookedSlots));

        return $this->success([
            'date' => $date,
            'slots' => $availableSlots,
            'booked' => array_values($bookedSlots),
        ]);
    }

    public function book(BookSevaRequest $request, Seva $seva): JsonResponse
    {
        $validated = $request->validated();
        $devotee = $request->user();
        $quantity = $validated['quantity'] ?? 1;
        $totalAmount = (float) $seva->price * $quantity;

        if ($seva->requires_booking && !empty($validated['slot_time'])) {
            $slotTaken = SevaBooking::where('seva_id', $seva->id)
                ->where('booking_date', $validated['booking_date'])
                ->where('slot_time', $validated['slot_time'])
                ->whereNotIn('status', ['cancelled', 'refunded'])
                ->exists();

            if ($slotTaken) {
                return $this->error('This slot is already booked. Please choose another.', 409);
            }
        }

        try {
            $result = DB::transaction(function () use ($seva, $validated, $devotee, $quantity, $totalAmount) {
                $paymentId = (string) Str::uuid();

                // Test mode: skip Razorpay, directly confirm
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

            Log::info('Seva booking confirmed (test mode)', [
                'booking_id' => $result['booking']->id,
                'amount' => $totalAmount,
            ]);

            return $this->success([
                'booking_id' => $result['booking']->id,
                'seva_name' => $seva->name,
                'booking_date' => $result['booking']->booking_date->format('d M Y'),
                'slot_time' => $result['booking']->slot_time,
                'amount' => $totalAmount,
                'status' => 'confirmed',
                'message' => 'સેવા બુકિંગ સફળ! (Test mode — Razorpay pending)',
            ], 'સેવા સફળતાપૂર્વક બુક થઈ.');

        } catch (\Exception $e) {
            Log::error('Seva booking failed', ['error' => $e->getMessage()]);
            return $this->error('બુકિંગ નિષ્ફળ. ફરી પ્રયાસ કરો.', 500);
        }
    }
}
