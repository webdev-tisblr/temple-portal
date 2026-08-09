<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SlotUnavailableException;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Services\HallAvailabilityService;
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
    public function __construct(
        private readonly HallAvailabilityService $availability,
    ) {}

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
                    // Item 4.2 — additive. 1 = single-day only (today's
                    // behaviour); the app only offers range selection when
                    // this is > 1. Old builds simply ignore the key.
                    'max_booking_days' => $h->maxBookingDays(),
                    'booking_cutoff_hours' => $h->bookingCutoffHours(),
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
        $request->validate([
            'date' => 'required|date|after_or_equal:today',
            // Item 4.2 — optional range end. Absent ⇒ single day, so the
            // shipped app (which never sends it) is unaffected.
            'end_date' => 'nullable|date|after_or_equal:date',
        ]);

        $date = (string) $request->query('date');
        $end = (string) ($request->query('end_date') ?: $date);

        $verdict = $this->availability->checkRange($hall, $date, $end);
        $blackoutReason = $hall->blackoutReason($date);

        // The six original keys keep their exact names AND meanings;
        // reason_code / days / end_date are additive (item 4.1 / 4.2).
        return $this->success([
            'date' => $date,
            'end_date' => $end,
            'days' => $verdict['days'],
            'full_day_available' => $verdict['ok'],
            'morning_available' => $verdict['ok'],
            'evening_available' => $verdict['ok'],
            'blocked' => $blackoutReason !== null,
            'blocked_reason' => $blackoutReason,
            'reason_code' => $verdict['reason_code'],
            'reason' => $verdict['reason'],
            'conflicting_dates' => $verdict['conflicting_dates'],
        ]);
    }

    /**
     * Per-date availability for one calendar month ('YYYY-MM'), for the
     * Year → Month → dates picker. One query covers the whole month.
     *
     * A date is unavailable when ANY occupying booking's
     * [booking_date, end_date] window covers it (item 4.2), when the admin
     * blacked it out, or when it is inside the cut-off window (item 4.3).
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

        $dates = array_map(static fn (array $row): array => [
            'date' => $row['date'],
            'full_day_available' => $row['available'],
            'morning_available' => $row['available'],
            'evening_available' => $row['available'],
            'blocked' => $row['blackout_reason'] !== null,
            'blocked_reason' => $row['blackout_reason'],
            // Additive (item 4.1) — lets the UI say WHY, e.g. "Already
            // booked" vs "Booking closed", instead of a bare grey chip.
            'reason_code' => $row['reason_code'],
            'reason' => $row['reason'],
        ], $this->availability->monthAvailability($hall, (string) $month));

        return $this->success([
            'month' => $month,
            'dates' => $dates,
            'max_booking_days' => $hall->maxBookingDays(),
        ]);
    }

    /**
     * Server-authoritative quote for a date range (item 4.2). The client
     * must never compute the price — this is the only place it comes from.
     */
    public function rangeQuote(Request $request, Hall $hall): JsonResponse
    {
        $request->validate([
            'start' => ['required', 'date'],
            'end' => ['nullable', 'date'],
        ]);

        $start = Carbon::parse((string) $request->query('start'))->toDateString();
        $end = $request->query('end')
            ? Carbon::parse((string) $request->query('end'))->toDateString()
            : $start;

        $verdict = $this->availability->checkRange($hall, $start, $end);
        $price = $this->availability->priceFor($hall, $start, $end);

        return $this->success([
            'start' => $start,
            'end' => $end,
            'days' => $price['days'],
            'available' => $verdict['ok'],
            'reason_code' => $verdict['reason_code'],
            'reason' => $verdict['reason'],
            'conflicts' => array_map(static fn (string $d): array => [
                'date' => $d,
                'reason_code' => \App\Enums\UnavailableReason::HallBooked->value,
                'reason' => \App\Enums\UnavailableReason::HallBooked->label(),
            ], $verdict['conflicting_dates']),
            'price_per_day' => $price['price_per_day'],
            'total_amount' => $price['total'],
            'amount_paise' => (int) round($price['total'] * 100),
            'max_booking_days' => $hall->maxBookingDays(),
        ]);
    }

    /**
     * First bookable window from `from` forward (item 4.4). Replaces the
     * app's 12-request client-side month scan with one request.
     */
    public function nextAvailable(Request $request, Hall $hall): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'horizon_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $found = $this->availability->nextAvailable(
            $hall,
            $request->query('from') ? Carbon::parse((string) $request->query('from'))->toDateString() : null,
            (int) $request->query('horizon_days', 365),
            (int) $request->query('days', 1),
        );

        if ($found === null) {
            return $this->success([
                'found' => false,
                'date' => null,
                'end_date' => null,
                'label' => null,
            ]);
        }

        return $this->success([
            'found' => true,
            'date' => $found['date'],
            'end_date' => $found['end_date'],
            'days' => $found['days'],
            'label' => $found['date'] === $found['end_date']
                ? Carbon::parse($found['date'])->format('d M Y')
                : Carbon::parse($found['date'])->format('d M').' – '.Carbon::parse($found['end_date'])->format('d M Y'),
        ]);
    }

    public function book(Request $request, Hall $hall): JsonResponse
    {
        // Full-day only (2026-08-04). booking_type stays validated so an
        // old app version attempting a half-day booking gets a clear 422
        // instead of being silently charged the full-day price.
        $validated = $request->validate([
            'booking_date' => 'required|date|after_or_equal:today',
            // Item 4.2 — NULLABLE on purpose. App 1.4.8+32 sends only
            // booking_date, so end_date falls back to the same day and the
            // shipped build behaves exactly as before.
            'end_date' => 'nullable|date|after_or_equal:booking_date',
            'booking_type' => 'sometimes|in:full_day',
            'purpose' => 'required|string|max:500',
            'expected_guests' => 'nullable|integer|min:1',
            'contact_name' => 'required|string|max:255',
            'contact_phone' => 'required|string|max:15',
        ]);
        $validated['booking_type'] = 'full_day';

        $start = Carbon::parse($validated['booking_date'])->toDateString();
        $end = ! empty($validated['end_date'])
            ? Carbon::parse($validated['end_date'])->toDateString()
            : $start;

        // Range verdict: max_booking_days, cut-off (4.3), blackouts and
        // overlapping bookings. Runs before the transaction; the locked
        // re-check inside it is what actually closes the race.
        $verdict = $this->availability->checkRange($hall, $start, $end);
        if (! $verdict['ok']) {
            $status = $verdict['reason_code'] === \App\Enums\UnavailableReason::RangeTooLong->value ? 422 : 409;

            return $this->error(
                $verdict['reason'] ?? 'આ તારીખ પર હોલ પહેલેથી બુક છે.',
                $status,
            );
        }

        // Server-authoritative price — never trust a client-computed total.
        $price = $this->availability->priceFor($hall, $start, $end);
        $amount = $price['total'];
        $days = $price['days'];

        $devotee = $request->user();

        try {
            $result = DB::transaction(function () use ($hall, $validated, $devotee, $amount, $start, $end, $days) {
                // Race-safe re-check under a row lock. Before this, the hall
                // conflict check ran OUTSIDE the transaction with no lock, so
                // two devotees could both create a pending booking for the
                // same date and both pay. Mirrors the seva path.
                if (! $this->availability->hasRangeCapacityForUpdate($hall, $start, $end)) {
                    throw new SlotUnavailableException((string) __('availability.hall_taken_race'));
                }

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
                    'booking_date' => $start,
                    'end_date' => $end,
                    'days_count' => $days,
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
                'days' => $days,
            ]);

            return $this->success([
                'booking_id' => $result['booking']->id,
                'hall_name' => $hall->name,
                // Unchanged key + unchanged format ('d M Y') — it is the
                // RANGE START now, which for a single-day booking is
                // identical to what it always was.
                'booking_date' => $result['booking']->booking_date->format('d M Y'),
                'end_date' => $result['booking']->rangeEnd()->format('d M Y'),
                'days' => $days,
                'date_range_label' => $result['booking']->date_range_label,
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
        } catch (SlotUnavailableException $e) {
            return $this->error($e->getMessage(), 409);
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
                // Additive (item 4.2) — old builds keep reading booking_date.
                'end_date' => $b->rangeEnd()->toDateString(),
                'days' => (int) ($b->days_count ?: 1),
                'date_range_label' => $b->date_range_label,
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
