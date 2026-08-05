<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SlotUnavailableException;
use App\Http\Requests\BookSevaRequest;
use App\Http\Resources\SevaCollection;
use App\Http\Resources\SevaResource;
use App\Models\Payment;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Services\RazorpayService;
use App\Services\SevaReceiptService;
use App\Services\SevaSlotService;
use Carbon\Carbon;
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

        // Key on page too — the paginator resolves the current page at cache
        // time, so a page-agnostic key served page 1 to every page. The 'api_'
        // prefix keeps it disjoint from the web list cache (different shape).
        // The version stamp lets SevaObserver invalidate every page/category
        // combination with a single bump instead of enumerating keys.
        $ver = (int) Cache::get('sevas.cache.ver', 1);
        $page = max(1, (int) $request->query('page', 1));
        $cacheKey = "api_sevas.v{$ver}.".($category ?: 'all').".p{$page}";

        $sevas = Cache::remember($cacheKey, 600, function () use ($category) {
            $query = Seva::where('is_active', true)->orderBy('sort_order');

            if ($category) {
                $query->where('category', $category);
            }

            return $query->paginate(20);
        });

        return new SevaCollection($sevas);
    }

    /**
     * Admin-managed seva categories for the app's filter chips (mirror of
     * /gallery-categories). Localized via X-Locale; cached until the list
     * or the sevas change (SevaCategory::booted + SevaObserver both bust).
     * single_seva_id: when a category holds exactly one active seva, its id
     * — clients can deep-link straight to the seva instead of filtering.
     */
    public function categories(): JsonResponse
    {
        $categories = \App\Support\LocalizedCache::remember('seva.categories', 900, function () {
            $active = Seva::where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'category', 'image_path']);

            return \App\Models\SevaCategory::orderBy('sort_order')
                ->get()
                ->map(function (\App\Models\SevaCategory $c) use ($active) {
                    $sevas = $active->where('category', $c->slug)->values();
                    $image = $sevas->firstWhere(fn ($s) => ! empty($s->image_path))?->image_path;

                    return [
                        'slug' => $c->slug,
                        'name' => $c->name,
                        'seva_count' => $sevas->count(),
                        'single_seva_id' => $sevas->count() === 1 ? $sevas->first()->id : null,
                        // First active seva's image — the app home renders
                        // category cards just like the website home section.
                        'image_url' => $image ? image_url($image) : null,
                    ];
                });
        });

        return $this->success($categories);
    }

    public function show(Seva $seva): SevaResource
    {
        // Load the gallery only on detail (kept out of the cached list payload).
        $seva->load('media');

        return new SevaResource($seva);
    }

    /**
     * Return the bookable dates for this seva. Two modes:
     *   • month=YYYY-MM — every bookable date in that calendar month
     *     (Year → Month picker; up to 10 years ahead)
     *   • days=N (default 30) — legacy rolling window, kept for old
     *     app builds.
     */
    public function availableDates(Request $request, Seva $seva): JsonResponse
    {
        $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ]);

        if (($month = $request->query('month')) !== null) {
            $monthStart = Carbon::createFromFormat('!Y-m', $month);
            if ($monthStart->lt(now()->startOfMonth()) || $monthStart->gt(now()->startOfMonth()->addYears(10))) {
                return $this->error('Month out of the bookable range.', 422);
            }

            return $this->success([
                'month' => $month,
                'dates' => $this->slotService->getAvailableDatesForMonth($seva, $month),
            ]);
        }

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
            'slot_type' => $seva->getResolvedSlotConfig()['slot_type'] ?? 'time_slots',
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
            // Any confirmed/completed booking can serve a receipt — the
            // download endpoint regenerates the PDF on a NULL path, so
            // this flag must NOT depend on receipt_path being set.
            'receipt_available' => in_array($booking->status->value, ['confirmed', 'completed'], true),
            'receipt_number' => $booking->receipt_number,
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

    public function downloadReceipt(Request $request, SevaBooking $booking)
    {
        if ($booking->devotee_id !== $request->user()->id) {
            return $this->error('Unauthorized', 403);
        }
        if (! in_array($booking->status->value, ['confirmed', 'completed'], true)) {
            return $this->error('Receipt is available only after booking is confirmed.', 404);
        }

        // No R2 ->exists() probe — S3 HEADs hang, and the sweep NULLs
        // receipt_path when it deletes the object. Regenerate via the
        // service (not the job) so the devotee isn't re-notified.
        if (! $booking->receipt_path) {
            try {
                app(SevaReceiptService::class)->generateReceipt($booking);
                $booking->refresh();
            } catch (\Throwable $e) {
                Log::error('On-demand seva receipt regen failed (api)', [
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
            if (! $booking->receipt_path) {
                return $this->error('Receipt could not be generated. Try again shortly.', 500);
            }
        }

        $filename = 'Seva_Receipt_'.str_replace('/', '-', (string) ($booking->receipt_number ?? $booking->id)).'.pdf';

        return private_file_redirect($booking->receipt_path, $filename);
    }

    public function book(BookSevaRequest $request, Seva $seva): JsonResponse
    {
        $validated = $request->validated();
        $devotee = $request->user();
        $quantity = $validated['quantity'] ?? 1;

        // If the seva is configured for product selection, the selection is
        // required, must be one of the linked products, and the seva price =
        // the selected product's price (or the selected variant's price).
        $unitPrice = (float) $seva->price;
        $variantLabel = null;
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

            if ($selectedProduct->has_variants && ! empty($selectedProduct->variants)) {
                $variantLabel = $validated['selected_variant_label'] ?? null;
                if (empty($variantLabel)) {
                    return $this->error('કૃપા કરી વિકલ્પ પસંદ કરો.', 422);
                }
                $variantPrice = $selectedProduct->getVariantPrice($variantLabel);
                if ($variantPrice === null) {
                    return $this->error('પસંદ કરેલો વિકલ્પ ઉપલબ્ધ નથી.', 422);
                }
                // Zero-priced option = the option doesn't set the price;
                // the seva's own price stays in effect (mirrors starts_from).
                if ((float) $variantPrice > 0) {
                    $unitPrice = (float) $variantPrice;
                }
            } elseif ((float) $selectedProduct->price > 0) {
                $unitPrice = (float) $selectedProduct->price;
            }
        }
        $totalAmount = $unitPrice * $quantity;

        // Full-day / full-week sevas have no time slot — force slot_time to the
        // mode sentinel so capacity checks + storage stay consistent regardless
        // of what the client sent.
        $slotType = $this->slotService->slotType($this->slotService->configFor($seva));
        if ($slotType !== SevaSlotService::SLOT_TYPE_TIME) {
            $validated['slot_time'] = $slotType;
        }

        // Validate slot via service
        $error = $this->slotService->validateBooking($seva, $validated['booking_date'], $validated['slot_time'] ?? null);
        if ($error) {
            return $this->error($error, 409);
        }

        try {
            $result = DB::transaction(function () use ($seva, $validated, $devotee, $quantity, $totalAmount, $variantLabel) {
                // Race-safe capacity re-check under a row lock (see
                // SevaSlotService::hasSlotCapacityForUpdate). Closes the
                // window between validateBooking() and the insert.
                if (! $this->slotService->hasSlotCapacityForUpdate(
                    $seva, $validated['booking_date'], $validated['slot_time'] ?? null
                )) {
                    throw new SlotUnavailableException(
                        'This slot was just booked by someone else. Please choose another.'
                    );
                }

                $paymentId = (string) Str::uuid();
                $receipt = 'SEVA-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

                $razorpayService = app(RazorpayService::class);
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
                    'selected_variant_label' => $variantLabel,
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
                'razorpay_key_id' => SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'amount_paise' => (int) round($totalAmount * 100),
                'devotee_name' => $devotee->name,
                'devotee_phone' => $devotee->phone,
            ], 'સેવા બુકિંગ બનાવ્યું. પેમેન્ટ પૂર્ણ કરો.');

        } catch (SlotUnavailableException $e) {
            return $this->error($e->getMessage(), 409);
        } catch (\Exception $e) {
            Log::error('Seva booking failed', ['error' => $e->getMessage()]);

            return $this->error('બુકિંગ નિષ્ફળ. ફરી પ્રયાસ કરો.', 500);
        }
    }
}
