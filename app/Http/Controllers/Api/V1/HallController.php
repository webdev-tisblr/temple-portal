<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\RazorpayService;
use App\Support\LocalizedCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HallController extends BaseApiController
{
    public function index(): JsonResponse
    {
        $halls = LocalizedCache::remember('halls.active', 900, function () {
            return Hall::where('is_active', true)
                ->with('media')
                ->get()
                ->map(fn (Hall $h) => [
                    'id' => $h->id,
                    // Resolved (server locale) + all variants so the app can
                    // switch language client-side without refetching.
                    'name' => $h->name,
                    'name_gu' => $h->name_gu,
                    'name_hi' => $h->name_hi,
                    'name_en' => $h->name_en,
                    // Admin edits these in a RichEditor, so the stored value
                    // is HTML — but the app renders hall descriptions with a
                    // plain Text() widget (raw "<p>…" was user-visible on the
                    // hall cards, 2026-07-26). The app never needs markup
                    // here, so serve clean text (text_preview, the same helper web cards use). Web blades read the model
                    // directly and keep the rich HTML.
                    'description' => text_preview($h->description),
                    'description_gu' => text_preview($h->description_gu),
                    'description_hi' => text_preview($h->description_hi),
                    'description_en' => text_preview($h->description_en),
                    'capacity' => $h->capacity,
                    'price_per_day' => (float) $h->price_per_day,
                    // price_per_half_day removed 2026-08-04 — bookings are
                    // full-day only. Old app versions fall back to 0 and
                    // never send half-day (server would 422 anyway).
                    'amenities' => $h->amenities ?? [],
                    'rules' => $h->rules,
                    'rules_gu' => $h->rules_gu,
                    'rules_hi' => $h->rules_hi,
                    'rules_en' => $h->rules_en,
                    'image_url' => $h->image_path ? image_url($h->image_path) : null,
                    'media' => $h->media->map(fn ($m) => [
                        'type' => $m->media_type,
                        'url' => $m->media_type === 'video'
                            ? $m->video_url
                            : ($m->image_path ? image_url($m->image_path) : null),
                    ])->filter(fn ($x) => $x['url'] !== null)->values(),
                ]);
        });

        return $this->success($halls);
    }

    public function availability(Request $request, Hall $hall): JsonResponse
    {
        $request->validate(['date' => 'required|date|after_or_equal:today']);

        $date = $request->query('date');
        // Admin blockout wins over everything; otherwise full-day only
        // (2026-08-04): ANY pending/confirmed booking blocks the date.
        // The three flags are kept (all equal) so older app versions
        // keep rendering availability correctly.
        $blackoutReason = $hall->blackoutReason((string) $date);
        $booked = $blackoutReason !== null
            || HallBooking::where('hall_id', $hall->id)
                ->where('booking_date', $date)
                ->whereIn('status', ['pending', 'confirmed'])
                ->exists();

        return $this->success([
            'date' => $date,
            'full_day_available' => ! $booked,
            'morning_available' => ! $booked,
            'evening_available' => ! $booked,
            'blocked' => $blackoutReason !== null,
            'blocked_reason' => $blackoutReason,
        ]);
    }

    /**
     * Per-date availability for one calendar month ('YYYY-MM'), for the
     * Year → Month → dates picker. One query covers the whole month.
     */
    public function availableDates(Request $request, Hall $hall): JsonResponse
    {
        $request->validate([
            'month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        $month = $request->query('month');
        $monthStart = Carbon::createFromFormat('!Y-m', $month);
        if ($monthStart->lt(now()->startOfMonth()) || $monthStart->gt(now()->startOfMonth()->addYears(10))) {
            return $this->error('Month out of the bookable range.', 422);
        }

        $start = $monthStart->copy()->max(now()->startOfDay());
        $end = $monthStart->copy()->endOfMonth();

        // Full-day only: any pending/confirmed booking blocks the date.
        // One query covers the month; the three flags stay (all equal)
        // for older app versions.
        $bookedDates = HallBooking::where('hall_id', $hall->id)
            ->whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', ['pending', 'confirmed'])
            ->pluck('booking_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())
            ->unique()
            ->flip();

        $dates = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $key = $cursor->toDateString();
            // Admin blockout wins over bookings; older apps just see
            // full_day_available=false and disable the chip.
            $blackoutReason = $hall->blackoutReason($key);
            $available = $blackoutReason === null && ! $bookedDates->has($key);

            $dates[] = [
                'date' => $key,
                'full_day_available' => $available,
                'morning_available' => $available,
                'evening_available' => $available,
                'blocked' => $blackoutReason !== null,
                'blocked_reason' => $blackoutReason,
            ];
        }

        return $this->success([
            'month' => $month,
            'dates' => $dates,
        ]);
    }

    public function book(Request $request, Hall $hall): JsonResponse
    {
        // Full-day only (2026-08-04). booking_type stays validated so an
        // old app version attempting a half-day booking gets a clear 422
        // instead of being silently charged the full-day price.
        $validated = $request->validate([
            'booking_date' => 'required|date|after_or_equal:today',
            'booking_type' => 'sometimes|in:full_day',
            'purpose' => 'required|string|max:500',
            'expected_guests' => 'nullable|integer|min:1',
            'contact_name' => 'required|string|max:255',
            'contact_phone' => 'required|string|max:15',
        ]);
        $validated['booking_type'] = 'full_day';

        $amount = (float) $hall->price_per_day;

        // Refuse overlapping bookings — ANY pending/confirmed booking on
        // the date blocks it (legacy half-day rows block the whole date).
        $conflict = HallBooking::where('hall_id', $hall->id)
            ->where('booking_date', $validated['booking_date'])
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();

        if ($conflict) {
            return $this->error('આ તારીખ પર હોલ પહેલેથી બુક છે.', 409);
        }

        $devotee = $request->user();

        try {
            $result = DB::transaction(function () use ($hall, $validated, $devotee, $amount) {
                $receipt = 'HALL-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
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
            ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
            ->orderByDesc('created_at')
            ->paginate(20);

        $data = $bookings->getCollection()
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
            ])
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'last_page' => $bookings->lastPage(),
                'total' => $bookings->total(),
            ],
        ]);
    }

    public function downloadInvoice(Request $request, HallBooking $booking)
    {
        if ($booking->devotee_id !== $request->user()->id) {
            return $this->error('Unauthorized', 403);
        }
        // 'completed' too — the app shows the download button for past
        // (completed) bookings and this gate used to 404 them.
        if (! in_array($booking->status, ['confirmed', 'completed'], true)) {
            return $this->error('Invoice is generated only after booking is confirmed.', 404);
        }

        // No R2 ->exists() probe — S3 HEADs from Hostinger hang, and the
        // sweep NULLs invoice_path when it deletes the object.
        if (! $booking->invoice_path) {
            // Service, not the GenerateHallInvoice job — self-heal regen
            // must not re-notify the customer on every redownload.
            try {
                app(\App\Services\HallInvoiceService::class)->generateInvoice($booking);
                $booking->refresh();
            } catch (\Throwable $e) {
                Log::error('On-demand hall invoice regen failed (api)', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
            if (! $booking->invoice_path) {
                return $this->error('Invoice could not be generated. Try again shortly.', 500);
            }
        }

        // Redirect to a presigned R2 URL instead of proxying bytes through PHP.
        $filename = "Hall_Booking_{$booking->id}.pdf";

        return private_file_redirect($booking->invoice_path, $filename);
    }
}
