<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
            ? $hall->price_per_day
            : $hall->price_per_half_day;

        try {
            $result = DB::transaction(function () use ($hall, $validated, $request, $amount) {
                $payment = Payment::create([
                    'id' => (string) Str::uuid(),
                    'razorpay_order_id' => 'test_' . Str::random(14),
                    'amount' => $amount,
                    'currency' => 'INR',
                    'status' => 'captured',
                    'method' => 'test',
                    'paid_at' => now(),
                    'description' => "Hall booking - {$hall->name}",
                ]);

                return HallBooking::create([
                    'devotee_id' => $request->user()->id,
                    'hall_id' => $hall->id,
                    'booking_date' => $validated['booking_date'],
                    'booking_type' => $validated['booking_type'],
                    'purpose' => $validated['purpose'],
                    'expected_guests' => $validated['expected_guests'] ?? null,
                    'contact_name' => $validated['contact_name'],
                    'contact_phone' => $validated['contact_phone'],
                    'total_amount' => $amount,
                    'status' => 'confirmed',
                    'payment_id' => $payment->id,
                ]);
            });

            return $this->success([
                'booking_id' => $result->id,
                'hall_name' => $hall->name,
                'booking_date' => $result->booking_date->format('d M Y'),
                'booking_type' => $result->booking_type,
                'amount' => (float) $amount,
                'status' => 'confirmed',
            ], 'હોલ બુકિંગ સફળ!');
        } catch (\Exception $e) {
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
