<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\BookSevaRequest;
use App\Models\Payment;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\RazorpayService;
use App\Services\SevaSlotService;
use Artesaos\SEOTools\Facades\SEOMeta;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SevaWebController extends Controller
{
    public function index(): View
    {
        // Distinct key from the API list cache — the API caches a paginator
        // under active_sevas*, and sharing a key let whichever ran first
        // serve the wrong shape (web list silently truncated to 20).
        $sevas = Cache::remember('web_active_sevas', 600, function () {
            return Seva::where('is_active', true)
                ->orderBy('sort_order')
                ->get();
        });

        $grouped = $sevas->groupBy(fn ($seva) => $seva->getRawOriginal('category'));

        // Tabs follow the admin's category sort (Seva Categories); slugs not
        // in the managed list (legacy/orphaned) are appended so their sevas
        // stay reachable. Only categories that actually have sevas render.
        $categoryNames = \App\Models\SevaCategory::namesBySlug();
        $present = $grouped->keys();
        $categories = collect(array_keys($categoryNames))
            ->intersect($present)
            ->concat($present->diff(array_keys($categoryNames)))
            ->values();

        // ?category=<slug> preselects a tab (home-page category cards link
        // here); anything unknown falls back to "all".
        $requested = (string) request('category', '');
        $initialCategory = $categories->contains($requested) ? $requested : 'all';

        SEOMeta::setTitle('સેવા અને પૂજા — શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        SEOMeta::setDescription('શ્રી પાતાળિયા હનુમાનજી મંદિરમાં સેવા અને પૂજા ઓનલાઈન બુક કરો.');

        return view('pages.seva.index', compact('sevas', 'grouped', 'categories', 'categoryNames', 'initialCategory'));
    }

    public function show(Seva $seva): View
    {
        SEOMeta::setTitle("{$seva->name} — શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ");
        SEOMeta::setDescription($seva->description ?? '');

        $linkedProducts = $seva->hasProductSelection() ? $seva->getLinkedProductsList() : collect();

        return view('pages.seva.show', compact('seva', 'linkedProducts'));
    }

    public function book(BookSevaRequest $request, Seva $seva): View|RedirectResponse
    {
        $validated = $request->validated();
        $devotee = Auth::guard('devotee')->user();
        $quantity = (int) ($validated['quantity'] ?? 1);

        // Price = the selected product's price (or selected variant's price)
        // when the seva uses product selection; otherwise the seva's own price.
        // (Previously this used $seva->price unconditionally, ignoring the
        // chosen product — inconsistent with the API and the displayed price.)
        $unitPrice = (float) $seva->price;
        $variantLabel = null;
        if ($seva->hasProductSelection()) {
            $selectedId = $validated['selected_product_id'] ?? null;
            if (empty($selectedId)) {
                return back()->withErrors(['selected_product_id' => 'કૃપા કરી સેવા સાથેનું ઉત્પાદન પસંદ કરો.']);
            }
            $selectedProduct = $seva->getLinkedProductsList()->firstWhere('id', (int) $selectedId);
            if (! $selectedProduct) {
                return back()->withErrors(['selected_product_id' => 'પસંદ કરેલું ઉત્પાદન આ સેવા માટે ઉપલબ્ધ નથી.']);
            }
            if ($selectedProduct->has_variants && ! empty($selectedProduct->variants)) {
                $variantLabel = $validated['selected_variant_label'] ?? null;
                if (empty($variantLabel)) {
                    return back()->withErrors(['selected_variant_label' => 'કૃપા કરી વિકલ્પ પસંદ કરો.']);
                }
                $variantPrice = $selectedProduct->getVariantPrice($variantLabel);
                if ($variantPrice === null) {
                    return back()->withErrors(['selected_variant_label' => 'પસંદ કરેલો વિકલ્પ ઉપલબ્ધ નથી.']);
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
        $validated['selected_variant_label'] = $variantLabel;
        $totalAmount = $unitPrice * $quantity;

        // Full-day / full-week sevas have no time slot — force slot_time to the
        // mode sentinel so capacity checks + storage stay consistent.
        $slotSvc = app(SevaSlotService::class);
        $slotType = $slotSvc->slotType($slotSvc->configFor($seva));
        if ($slotType !== SevaSlotService::SLOT_TYPE_TIME) {
            $validated['slot_time'] = $slotType;
        }

        // Validate slot via service (acceptance period, blackout, capacity)
        $slotError = $slotSvc->validateBooking($seva, $validated['booking_date'], $validated['slot_time'] ?? null);
        if ($slotError) {
            return back()->withErrors(['slot_time' => $slotError]);
        }

        // Extra-field answers, with any photo uploaded to R2 first. Done
        // BEFORE the transaction: an upload is slow network I/O and must not
        // be holding a row lock (2026-08-13).
        $extraData = \App\Support\ExtraFieldValues::store(
            $request, $seva->extra_fields, $validated['extra_data'] ?? null, 'seva-extras'
        );

        // TEST MODE — skip Razorpay, direct confirm
        if (config('razorpay.test_mode')) {
            return $this->bookTestMode($seva, $validated, $devotee, $quantity, $totalAmount, $extraData);
        }

        // REAL PAYMENT MODE
        try {
            $result = DB::transaction(function () use ($seva, $validated, $devotee, $quantity, $totalAmount, $extraData) {
                // Race-safe capacity re-check under a row lock — closes the
                // window between validateBooking() above and the insert
                // below where two devotees could grab the same last slot.
                if (! app(SevaSlotService::class)->hasSlotCapacityForUpdate(
                    $seva, $validated['booking_date'], $validated['slot_time'] ?? null
                )) {
                    throw new \App\Exceptions\SlotUnavailableException(
                        'This slot was just booked by someone else. Please choose another.'
                    );
                }

                $paymentId = (string) Str::uuid();
                $receipt = 'SEVA-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));

                $razorpayService = app(RazorpayService::class);
                $amountInPaise = (int) round($totalAmount * 100);

                $razorpayOrder = $razorpayService->createOrder($amountInPaise, $receipt, [
                    'devotee_id' => $devotee->id,
                    'seva_id' => $seva->id,
                    'booking_date' => $validated['booking_date'],
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
                    'selected_variant_label' => $validated['selected_variant_label'] ?? null,
                    'extra_data' => $extraData,
                ]);

                return [
                    'booking' => $booking,
                    'payment' => $payment,
                    'razorpay_order' => $razorpayOrder,
                ];
            });

            return view('pages.seva.checkout', [
                'razorpayKeyId' => \App\Models\SystemSetting::getValue('razorpay_key_id', config('razorpay.key_id')),
                'orderId' => $result['razorpay_order']->id,
                'amount' => (int) round($totalAmount * 100),
                'currency' => 'INR',
                'description' => $seva->name_en . ' - ' . $validated['booking_date'],
                'devoteeName' => $devotee->name,
                'devoteePhone' => $devotee->phone,
                'devoteeEmail' => $devotee->email ?? '',
            ]);

        } catch (\App\Exceptions\SlotUnavailableException $e) {
            return back()->withErrors(['slot_time' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Web seva booking failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['booking' => 'બુકિંગ બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    private function bookTestMode(Seva $seva, array $validated, $devotee, int $quantity, float $totalAmount, ?array $extraData = null): View|RedirectResponse
    {
        try {
            $result = DB::transaction(function () use ($seva, $validated, $devotee, $quantity, $totalAmount, $extraData) {
                if (! app(SevaSlotService::class)->hasSlotCapacityForUpdate(
                    $seva, $validated['booking_date'], $validated['slot_time'] ?? null
                )) {
                    throw new \App\Exceptions\SlotUnavailableException(
                        'This slot was just booked by someone else. Please choose another.'
                    );
                }

                $paymentId = (string) Str::uuid();

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
                    'sankalp' => $validated['sankalp'] ?? null,
                    'selected_product_id' => $validated['selected_product_id'] ?? null,
                    'selected_variant_label' => $validated['selected_variant_label'] ?? null,
                    'extra_data' => $extraData,
                ]);

                // No Donation row for seva bookings — seva payments are
                // not 80G eligible. See PaymentCaptureService doc-comment.
                return ['booking' => $booking, 'payment' => $payment];
            });

            Log::info('Web seva booking confirmed (test mode)', ['booking_id' => $result['booking']->id]);

            // Same post-capture path as live payments: receipt PDF + the
            // single seva.booking.confirmed dispatch live in the job.
            // Without this, test-mode seva bookings sent nothing at all.
            try {
                \App\Jobs\GenerateSevaReceipt::dispatchSync($result['booking']);
            } catch (\Throwable $e) {
                Log::error('Test-mode seva receipt job failed', [
                    'booking_id' => $result['booking']->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Greeting card is its own deliverable (seva.greeting_card),
            // mirroring the live-payment path in PaymentCaptureService.
            try {
                // Only when the seva is today or past — a future seva is
                // carded on its own morning by seva:send-day-of-cards. Same
                // rule as the Razorpay capture path, asked of the one service
                // that owns it so the two cannot drift.
                if (app(\App\Services\PaymentCaptureService::class)->sevaCardIsDueNow($result['booking'])) {
                    \App\Jobs\GenerateSevaGreetingCard::dispatchSync($result['booking']);
                }
            } catch (\Throwable $e) {
                Log::error('Test-mode seva greeting card job failed', [
                    'booking_id' => $result['booking']->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return view('pages.seva.booking-success', [
                'verified' => true,
                'booking' => $result['booking']->load('seva'),
            ]);

        } catch (\App\Exceptions\SlotUnavailableException $e) {
            return back()->withErrors(['slot_time' => $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('Web seva booking failed', ['error' => $e->getMessage()]);
            return back()->withErrors(['booking' => 'બુકિંગ બનાવવામાં નિષ્ફળ. કૃપા કરીને ફરી પ્રયાસ કરો.']);
        }
    }

    public function bookingSuccess(Request $request): View
    {
        $paymentId = $request->query('payment_id');
        $orderId = $request->query('order_id');
        $signature = $request->query('signature');

        $verified = false;
        $booking = null;

        if ($paymentId && $orderId && $signature) {
            $razorpayService = app(RazorpayService::class);
            $verified = $razorpayService->verifyPaymentSignature($orderId, $paymentId, $signature);

            if ($verified) {
                $payment = Payment::where('razorpay_order_id', $orderId)->first();
                if ($payment) {
                    // Delegate the entire capture flow to the central
                    // service — same code path as the API
                    // /payments/verify endpoint and the Razorpay webhook.
                    // markCaptured is idempotent (Payment::lockForUpdate
                    // + status check), so a duplicate hit from this
                    // callback after the webhook already fired is a
                    // no-op. Web flows used to do a partial capture
                    // here (just flip payment + booking status), which
                    // silently skipped notifications and other side
                    // effects.
                    app(\App\Services\PaymentCaptureService::class)->markCaptured(
                        $payment,
                        $paymentId,
                    );

                    $booking = SevaBooking::where('payment_id', $payment->id)
                        ->with('seva.assignee', 'selectedProduct')
                        ->first();
                }
            }
        }

        return view('pages.seva.booking-success', compact('verified', 'booking'));
    }

    public function bookingFailure(): View
    {
        return view('pages.seva.booking-failure');
    }

    /**
     * Permanent public greeting-card link for a seva booking — mirrors
     * DonationWebController::greetingCard. Cards live on r2_private with
     * aggressive retention; the sweep NULLs greeting_card_path when it
     * deletes the object, so a non-null path means the file is present
     * (no R2 ->exists() probe). Regenerates on miss in the devotee's
     * current preferred language.
     */
    public function greetingCard(string $bookingId): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\RedirectResponse
    {
        $booking = SevaBooking::findOrFail($bookingId);

        // Also regenerates when the cached card is in a language the devotee
        // no longer uses (paths carry a `-{locale}` suffix).
        if (app(\App\Services\GreetingCardService::class)->sevaCardNeedsRegeneration($booking)) {
            $regeneratedPath = app(\App\Services\GreetingCardService::class)->generateForSevaBooking($booking);
            $booking->refresh();
            // null return = the seva has no card template configured at
            // all — no card was ever supposed to exist. 404 is correct,
            // unless an older card already exists to fall back on.
            if (! $regeneratedPath && empty($booking->greeting_card_path)) {
                abort(404);
            }
        }

        return private_file_redirect($booking->greeting_card_path, null, inline: true, contentType: 'image/png');
    }
}
