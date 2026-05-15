<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\BookSevaRequest;
use App\Http\Resources\SevaCollection;
use App\Http\Resources\SevaResource;
use App\Models\Payment;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\SevaSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SevaController extends BaseApiController
{
    public function __construct(
        private readonly SevaSlotService $slotService,
    ) {}

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

    /**
     * Return the next-N-days set of dates (default 30) on which this
     * seva can be booked. Mobile date-carousel uses this to hide
     * non-bookable dates entirely (blackouts, fully-booked, outside
     * acceptance window).
     */
    public function availableDates(Request $request, Seva $seva): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ]);

        $days = (int) $request->query('days', 30);
        $dates = $this->slotService->getAvailableDates($seva, $days);

        return $this->success([
            'days' => $days,
            'dates' => $dates,
        ]);
    }

    public function availableSlots(Request $request, Seva $seva): JsonResponse
    {
        $request->validate([
            'date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $date = $request->query('date');
        $availability = $this->slotService->getSlotAvailability($seva, $date);

        $response = [
            'date' => $date,
            'slots' => $availability['available'],
            'booked' => $availability['booked'],
            'slot_duration_minutes' => $seva->getSlotDurationMinutes(),
            'max_bookings_per_slot' => $seva->getMaxBookingsPerSlot(),
        ];

        if ($availability['blackout']) {
            $response['blackout'] = true;
            $response['blackout_reason'] = $availability['blackout_reason'];
        }

        if ($availability['message']) {
            $response['message'] = $availability['message'];
        }

        return $this->success($response);
    }

    public function bookings(Request $request): JsonResponse
    {
        $bookings = SevaBooking::with(['seva', 'selectedProduct'])
            ->where('devotee_id', $request->user()->id)
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $bookings->getCollection()->map(fn (SevaBooking $booking) => [
            'id' => $booking->id,
            'seva_name' => $booking->seva?->name,
            'seva_name_gu' => $booking->seva?->name_gu,
            'seva_name_hi' => $booking->seva?->name_hi,
            'seva_name_en' => $booking->seva?->name_en,
            'seva_image_url' => $booking->seva?->image_path ? image_url($booking->seva->image_path) : null,
            'booking_date' => $booking->booking_date->toDateString(),
            'slot_time' => $booking->slot_time,
            'quantity' => $booking->quantity,
            'total_amount' => (float) $booking->total_amount,
            'status' => $booking->status->value,
            'devotee_name_for_seva' => $booking->devotee_name_for_seva,
            'sankalp' => $booking->sankalp,
            'selected_product' => $booking->selectedProduct ? [
                'id' => $booking->selectedProduct->id,
                'name' => $booking->selectedProduct->name,
                'name_gu' => $booking->selectedProduct->name_gu,
                'image_url' => $booking->selectedProduct->image_path
                    ? image_url($booking->selectedProduct->image_path)
                    : null,
            ] : null,
            'created_at' => $booking->created_at?->toISOString(),
        ]);

        return $this->success([
            'bookings' => $data,
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'total' => $bookings->total(),
            ],
        ]);
    }

    public function book(BookSevaRequest $request, Seva $seva): JsonResponse
    {
        $validated = $request->validated();
        $devotee = $request->user();
        $quantity = $validated['quantity'] ?? 1;

        // If the seva is configured for product selection, the selection is required,
        // must be one of the linked products, and the seva price = product price.
        $unitPrice = (float) $seva->price;
        if ($seva->hasProductSelection()) {
            $linked = $seva->getLinkedProductsList();
            $selectedId = $validated['selected_product_id'] ?? null;
            if (empty($selectedId)) {
                return $this->error('કૃપા કરી સેવા સાથેનું ઉત્પાદન પસંદ કરો.', 422);
            }
            $selectedProduct = $linked->firstWhere('id', (int) $selectedId);
            if (! $selectedProduct) {
                return $this->error('પસંદ કરેલું ઉત્પાદન આ સેવા માટે ઉપલબ્ધ નથી.', 422);
            }
            $unitPrice = (float) $selectedProduct->price;
        }
        $totalAmount = $unitPrice * $quantity;

        // Validate slot via service
        $error = $this->slotService->validateBooking($seva, $validated['booking_date'], $validated['slot_time'] ?? null);
        if ($error) {
            return $this->error($error, 409);
        }

        try {
            $result = DB::transaction(function () use ($seva, $validated, $devotee, $quantity, $totalAmount) {
                $paymentId = (string) Str::uuid();
                $receipt = 'SEVA-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $razorpayService = app(\App\Services\RazorpayService::class);
                $amountInPaise = (int) round($totalAmount * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'seva_id' => $seva->id,
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
                    'sankalp' => $validated['sankalp'] ?? null,
                    'selected_product_id' => $validated['selected_product_id'] ?? null,
                ]);

                return ['booking' => $booking, 'payment' => $payment, 'razorpay_order' => $razorpayOrder];
            });

            Log::info('Seva booking created, awaiting payment', [
                'booking_id' => $result['booking']->id,
                'razorpay_order_id' => $result['razorpay_order']->id,
                'amount' => $totalAmount,
            ]);

            return $this->success([
                'booking_id' => $result['booking']->id,
                'seva_name' => $seva->name,
                'booking_date' => $result['booking']->booking_date->format('d M Y'),
                'slot_time' => $result['booking']->slot_time,
                'amount' => $totalAmount,
                'status' => 'pending',
                'razorpay_order_id' => $result['razorpay_order']->id,
                'razorpay_key_id' => \App\Models\SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'amount_paise' => (int) round($totalAmount * 100),
                'devotee_name' => $devotee->name,
                'devotee_phone' => $devotee->phone,
            ], 'સેવા બુકિંગ બનાવ્યું. પેમેન્ટ પૂર્ણ કરો.');

        } catch (\Exception $e) {
            Log::error('Seva booking failed', ['error' => $e->getMessage()]);
            return $this->error('બુકિંગ નિષ્ફળ. ફરી પ્રયાસ કરો.', 500);
        }
    }
}
