<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\RazorpayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HallController extends BaseApiController
{
    public function index(): JsonResponse
    {
        $halls = Cache::remember('halls.active', 900, function () {
            return Hall::where('is_active', true)
                ->get()
                ->map(fn (Hall $h) => [
                    'id' => $h->id,
                    'name' => $h->name,
                    'description' => $h->description,
                    'capacity' => $h->capacity,
                    'price_per_day' => (float) $h->price_per_day,
                    'price_per_half_day' => (float) $h->price_per_half_day,
                    'amenities' => $h->amenities ?? [],
                    'rules' => $h->rules,
                    'image_url' => $h->image_path ? asset('storage/' . $h->image_path) : null,
                ]);
        });

        return $this->success($halls);
    }

    public function availability(Request $request, Hall $hall): JsonResponse
    {
        $request->validate(['date' => 'required|date|after_or_equal:today']);

        $date = $request->query('date');
        $bookings = HallBooking::where('hall_id', $hall->id)
            ->where('booking_date', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->pluck('booking_type')
            ->toArray();

        $fullDayBooked = in_array('full_day', $bookings);
        $morningBooked = in_array('half_day_morning', $bookings) || $fullDayBooked;
        $eveningBooked = in_array('half_day_evening', $bookings) || $fullDayBooked;

        return $this->success([
            'date' => $date,
            'full_day_available' => !$fullDayBooked && !$morningBooked && !$eveningBooked,
            'morning_available' => !$morningBooked,
            'evening_available' => !$eveningBooked,
        ]);
    }

    public function book(Request $request, Hall $hall): JsonResponse
    {
        $validated = $request->validate([
            'booking_date' => 'required|date|after_or_equal:today',
            'booking_type' => 'required|in:full_day,half_day_morning,half_day_evening',
            'purpose' => 'required|string|max:500',
            'expected_guests' => 'nullable|integer|min:1',
            'contact_name' => 'required|string|max:255',
            'contact_phone' => 'required|string|max:15',
        ]);

        $amount = $validated['booking_type'] === 'full_day'
            ? (float) $hall->price_per_day
            : (float) $hall->price_per_half_day;

        // Refuse overlapping bookings — same day, overlapping slot type,
        // pending or confirmed on another booking.
        $conflict = HallBooking::where('hall_id', $hall->id)
            ->where('booking_date', $validated['booking_date'])
            ->whereIn('status', ['pending', 'confirmed'])
            ->where(function ($q) use ($validated) {
                $q->where('booking_type', 'full_day');
                if ($validated['booking_type'] === 'full_day') {
                    return;
                }
                $q->orWhere('booking_type', $validated['booking_type']);
            })
            ->exists();

        if ($conflict) {
            return $this->error('આ તારીખ અને સમય પર હોલ પહેલેથી બુક છે.', 409);
        }

        $devotee = $request->user();

        try {
            $result = DB::transaction(function () use ($hall, $validated, $devotee, $amount) {
                $receipt = 'HALL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
                $razorpayService = app(RazorpayService::class);
                $amountInPaise = (int) round($amount * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'hall_id' => $hall->id,
                    'booking_type' => $validated['booking_type'],
                ]);

                $payment = Payment::create([
                    'id' => (string) Str::uuid(),
                    'razorpay_order_id' => $razorpayOrder->id,
                    'amount' => $amount,
                    'currency' => 'INR',
                    'status' => 'created',
                    'description' => "Hall booking - {$hall->name}",
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
                    'total_amount' => $amount,
                    'status' => 'pending',
                    'payment_id' => $payment->id,
                ]);

                return [
                    'booking' => $booking,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                ];
            });

            Log::info('Hall booking created, awaiting payment', [
                'booking_id' => $result['booking']->id,
                'razorpay_order_id' => $result['razorpay_order']->id,
                'amount' => $amount,
            ]);

            return $this->success([
                'booking_id' => $result['booking']->id,
                'hall_name' => $hall->name,
                'booking_date' => $result['booking']->booking_date->format('d M Y'),
                'booking_type' => $result['booking']->booking_type,
                'amount' => $amount,
                'amount_paise' => (int) round($amount * 100),
                'status' => 'pending',
                'razorpay_order_id' => $result['razorpay_order']->id,
                'razorpay_key_id' => SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'devotee_name' => $devotee->name,
                'devotee_phone' => $devotee->phone,
                'devotee_email' => $devotee->email,
            ], 'હોલ બુકિંગ બનાવ્યું. પેમેન્ટ પૂર્ણ કરો.');
        } catch (\Exception $e) {
            Log::error('Hall booking failed', ['error' => $e->getMessage()]);
            return $this->error('હોલ બુકિંગ નિષ્ફળ. ફરી પ્રયાસ કરો.', 500);
        }
    }

    public function myBookings(Request $request): JsonResponse
    {
        $bookings = HallBooking::with('hall')
            ->where('devotee_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (HallBooking $b) => [
                'id' => $b->id,
                'hall_name' => $b->hall?->name,
                'booking_date' => $b->booking_date->toDateString(),
                'booking_type' => $b->booking_type,
                'purpose' => $b->purpose,
                'total_amount' => (float) $b->total_amount,
                'status' => $b->status,
                'contact_name' => $b->contact_name,
                'created_at' => $b->created_at?->toISOString(),
            ]);

        return $this->success($bookings);
    }
}
