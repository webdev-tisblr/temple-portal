<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminUser;
use App\Models\Devotee;
use App\Models\Donation;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Support\DevoteeLocale;
use App\Support\PhoneNumber;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Item 6.1 — manual cash entry. Records money handed over in person at
 * the temple counter as a first-class Donation / SevaBooking / HallBooking
 * / store Order, on behalf of a walk-in devotee.
 *
 * THE DESIGN, in one sentence: build a synthetic *offline* Payment and put
 * it through PaymentCaptureService::markCaptured() unchanged, so a cash
 * entry is byte-for-byte the same event as an online one.
 *
 * Why not a sibling confirm path (the tempting shortcut):
 *   • Every dashboard, FinancialReport, campaign total, devotee history
 *     and public donor list filters on `payment.status = 'captured'`
 *     (23 call sites). A record with no captured Payment is invisible in
 *     all of them.
 *   • markCaptured() owns ~250 lines of orchestration — variant-aware
 *     locked stock decrement, six PDF jobs, seven notification triggers,
 *     the seva reminder schedule. The existing `bookTestMode` sibling
 *     paths have ALREADY drifted from it (store test-mode re-implements
 *     stock decrement without the deadlock ordering, the oversell
 *     Log::critical, or the shortfall floor). A second sibling would rot
 *     the same way. Going through markCaptured() inherits every future
 *     fix to it for free.
 *
 * ⚠️ THE ONE DETAIL THAT BREAKS EVERYTHING IF YOU GET IT WRONG:
 * the synthetic Payment is created with `status = 'created'`, NOT
 * 'captured'. markCaptured() early-exits on an already-captured payment
 * (its replay fast path), so pre-setting 'captured' produces a silent
 * no-op: the row exists, the booking stays `pending`, no receipt, no
 * notification, no stock movement, and no error anywhere.
 *
 * IDEMPOTENCY. Each form submission carries an `entry_token` minted once
 * when the page opens. It becomes `razorpay_order_id = 'cash_<token>'`,
 * a column that is already NOT NULL + UNIQUE — so a double-click, a
 * refresh-resubmit, or two clerks entering the same slip simultaneously
 * can only ever insert one Payment. The Payment insert is deliberately
 * the FIRST statement in the transaction, so a duplicate aborts before
 * any booking / order / donation row is written.
 */
class CounterEntryService
{
    public const TYPE_DONATION = 'donation';

    public const TYPE_SEVA = 'seva';

    public const TYPE_HALL = 'hall';

    public const TYPE_STORE = 'store';

    /**
     * Record type → the Filament Shield permission an admin must hold to
     * take cash for it.
     *
     * These four `create_*` permissions already existed and were DEAD:
     * all four resources hard-return `canCreate() === false` (deliberately
     * — "no booking may ever be hand-inserted without a payment"), so
     * nothing ever consulted them. Those rails stay exactly as they are;
     * this page is the one create path, and checking the existing
     * permissions here is what finally makes them mean something.
     *
     * Deliberately NOT a new `record_cash_<type>` permission set: gap G9
     * of the 2026-08-09 RBAC audit was six seeded permissions that nothing
     * ever checked, and inventing near-duplicates of four live ones would
     * be the same mistake in a new coat.
     *
     * @var array<string,string>
     */
    public const TYPE_PERMISSIONS = [
        self::TYPE_DONATION => 'create_donation',
        self::TYPE_SEVA => 'create_seva::booking',
        self::TYPE_HALL => 'create_hall::booking',
        self::TYPE_STORE => 'create_order',
    ];

    public function __construct(
        private readonly PaymentCaptureService $capture,
        private readonly ReceiptService $receipts,
        private readonly SevaSlotService $slots,
        private readonly HallAvailabilityService $halls,
    ) {}

    /** A fresh idempotency token for one counter interaction. */
    public static function newEntryToken(): string
    {
        return strtolower((string) Str::ulid());
    }

    /** Record types this admin may take cash for. */
    public function allowedTypesFor(?AdminUser $admin): array
    {
        if ($admin === null) {
            return [];
        }

        return array_values(array_filter(
            array_keys(self::TYPE_PERMISSIONS),
            fn (string $type): bool => $admin->can(self::TYPE_PERMISSIONS[$type]),
        ));
    }

    public function mayRecord(?AdminUser $admin, string $type): bool
    {
        return $admin !== null
            && isset(self::TYPE_PERMISSIONS[$type])
            && $admin->can(self::TYPE_PERMISSIONS[$type]);
    }

    /**
     * Server-authoritative total for a draft entry. The counter form shows
     * it as a read-only preview and record() recomputes it — the browser
     * never decides what the devotee is charged.
     *
     * @param  array<string,mixed>  $data
     * @return array{total: float, label: string, detail: ?string}
     */
    public function quote(array $data): array
    {
        return match ($data['record_type'] ?? null) {
            self::TYPE_DONATION => $this->quoteDonation($data),
            self::TYPE_SEVA => $this->quoteSeva($data),
            self::TYPE_HALL => $this->quoteHall($data),
            self::TYPE_STORE => $this->quoteStore($data),
            default => ['total' => 0.0, 'label' => '—', 'detail' => null],
        };
    }

    /**
     * THE ENTRY POINT. Creates (or finds) the devotee, writes the record
     * plus its synthetic offline Payment, then hands the Payment to
     * PaymentCaptureService so every side effect fires exactly as it does
     * for an online payment.
     *
     * @param  array<string,mixed>  $data
     * @return array{payment: Payment, record: Model, devotee: Devotee, type: string, label: string, duplicate: bool}
     *
     * @throws AuthorizationException|ValidationException
     */
    public function record(array $data, AdminUser $admin): array
    {
        $type = (string) ($data['record_type'] ?? '');

        if (! $this->mayRecord($admin, $type)) {
            throw new AuthorizationException(
                'You do not have permission to record a counter payment of this type.'
            );
        }

        $method = (string) ($data['payment_method'] ?? 'cash');
        if (! array_key_exists($method, Payment::OFFLINE_METHODS)) {
            throw ValidationException::withMessages([
                'payment_method' => 'Unknown payment method.',
            ]);
        }

        $token = trim((string) ($data['entry_token'] ?? ''));
        if ($token === '') {
            throw ValidationException::withMessages([
                'entry_token' => 'Missing entry token — reload the page and try again.',
            ]);
        }
        $orderId = Payment::OFFLINE_ORDER_PREFIX.strtolower($token);

        $paidAt = $this->resolvePaidAt($data['paid_on'] ?? null);

        // Replay guard #1 (cheap): this exact slip has already been
        // entered. Guard #2 is the UNIQUE index itself, below.
        $existing = Payment::where('razorpay_order_id', $orderId)->first();
        if ($existing !== null) {
            return $this->describeExisting($existing, $type);
        }

        $devotee = $this->resolveDevotee($data);
        $quote = $this->quote($data);

        if ($quote['total'] <= 0) {
            throw ValidationException::withMessages([
                'record_type' => 'The amount for this entry works out to zero — nothing to record.',
            ]);
        }

        try {
            $created = DB::transaction(function () use ($data, $type, $devotee, $quote, $orderId, $method, $paidAt, $admin) {
                $payment = $this->createStamped(Payment::class, [
                    'razorpay_order_id' => $orderId,
                    'amount' => $quote['total'],
                    'currency' => 'INR',
                    // MUST be 'created'. See the class doc-comment.
                    'status' => 'created',
                    'method' => $method,
                    'description' => $this->describeEntry($data, $quote, $admin),
                    'created_by_admin_id' => $admin->getKey(),
                ], $paidAt);

                $record = match ($type) {
                    self::TYPE_DONATION => $this->createDonation($data, $devotee, $payment, $quote, $paidAt),
                    self::TYPE_SEVA => $this->createSevaBooking($data, $devotee, $payment, $quote, $paidAt),
                    self::TYPE_HALL => $this->createHallBooking($data, $devotee, $payment, $quote, $paidAt),
                    self::TYPE_STORE => $this->createOrder($data, $devotee, $payment, $quote, $paidAt),
                };

                return ['payment' => $payment, 'record' => $record];
            });
        } catch (QueryException $e) {
            // Replay guard #2: two submissions raced past the pre-check and
            // collided on razorpay_order_id's UNIQUE index. The whole
            // transaction rolled back, so nothing partial survives.
            if ($this->isUniqueViolation($e)) {
                $winner = Payment::where('razorpay_order_id', $orderId)->first();
                if ($winner !== null) {
                    return $this->describeExisting($winner, $type);
                }
            }

            throw $e;
        }

        // OUTSIDE the creating transaction on purpose: markCaptured opens
        // its own, and its post-commit block renders PDFs + fans out
        // notifications. Nesting would hold the row locks through all of it.
        $this->capture->markCaptured(
            $created['payment'],
            null,
            $method,
            null,
            $paidAt,
        );

        return [
            'payment' => $created['payment']->fresh(),
            'record' => $created['record']->fresh(),
            'devotee' => $devotee,
            'type' => $type,
            'label' => $quote['label'],
            'duplicate' => false,
        ];
    }

    // ── Devotee find-or-create ───────────────────────────────────────

    /**
     * Look up an existing devotee by canonical phone, or create one.
     *
     * Phone goes through PhoneNumber::normalize() — the same canonicaliser
     * the OTP login uses. Storing '+91 98765 43210' here would produce a
     * devotee who can never log in and whose WhatsApp messages never
     * deliver (that is exactly gap G16 on DevoteeResource).
     *
     * @param  array<string,mixed>  $data
     *
     * @throws ValidationException
     */
    public function resolveDevotee(array $data): Devotee
    {
        $phone = PhoneNumber::normalize((string) ($data['phone'] ?? ''));

        if ($phone === null) {
            throw ValidationException::withMessages([
                'phone' => 'Enter a valid mobile number.',
            ]);
        }

        $existing = $this->findDevoteeByPhone($phone);
        if ($existing !== null) {
            return $existing;
        }

        // NB: spec 6.1.4 warns about a soft-deleted devotee permanently
        // blocking their phone via the non-soft-delete-aware UNIQUE index.
        // That hazard no longer exists — the SoftDeletes trait was dropped
        // from Devotee (and ten other models) in the 2026_05_13 migration
        // precisely because of that tombstone bug, so the lookup above sees
        // every row there is.
        $name = trim((string) ($data['devotee_name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages([
                'devotee_name' => 'No devotee exists on this number — enter their name to create one.',
            ]);
        }

        return Devotee::create([
            'name' => $name,
            'phone' => $phone,
            'email' => filled($data['devotee_email'] ?? null) ? $data['devotee_email'] : null,
            'city' => filled($data['devotee_city'] ?? null) ? $data['devotee_city'] : null,
            'language' => $data['devotee_language'] ?? DevoteeLocale::FALLBACK,
            'country' => 'India',
            'is_active' => true,
            // NOT OTP-verified — an admin typed this in. Keep it honest;
            // the devotee verifies for real on their first login.
            'phone_verified_at' => null,
        ]);
    }

    /** Canonical-phone lookup used by both the form preview and record(). */
    public function findDevoteeByPhone(?string $rawPhone): ?Devotee
    {
        $phone = PhoneNumber::normalize($rawPhone);

        return $phone === null ? null : Devotee::where('phone', $phone)->first();
    }

    // ── Per-type pricing ─────────────────────────────────────────────

    /** @param array<string,mixed> $data */
    private function quoteDonation(array $data): array
    {
        return [
            'total' => round((float) ($data['amount'] ?? 0), 2),
            'label' => 'Donation',
            'detail' => filled($data['purpose'] ?? null) ? (string) $data['purpose'] : null,
        ];
    }

    /** @param array<string,mixed> $data */
    private function quoteSeva(array $data): array
    {
        $seva = filled($data['seva_id'] ?? null) ? Seva::find($data['seva_id']) : null;
        if ($seva === null) {
            return ['total' => 0.0, 'label' => 'Seva booking', 'detail' => null];
        }

        $quantity = max(1, (int) ($data['quantity'] ?? 1));

        // A variable-price seva is whatever the devotee chose to give, but
        // never below the configured floor.
        if ($seva->is_variable_price) {
            $unit = (float) ($data['seva_amount'] ?? 0);
            $unit = max($unit, (float) $seva->min_price);
        } else {
            $unit = $this->sevaUnitPrice($seva, $data);
        }

        return [
            'total' => round($unit * $quantity, 2),
            'label' => 'Seva booking — '.$seva->name_en,
            'detail' => $quantity > 1 ? "{$quantity} × ₹".number_format($unit, 2) : null,
        ];
    }

    /**
     * Mirrors SevaWebController::book(): when a seva carries product
     * selection, the chosen product/variant price wins over the seva's own,
     * and a zero-priced variant means "this option does not set the price".
     *
     * @param  array<string,mixed>  $data
     */
    private function sevaUnitPrice(Seva $seva, array $data): float
    {
        $unit = (float) $seva->price;

        if (! $seva->hasProductSelection() || empty($data['selected_product_id'])) {
            return $unit;
        }

        $product = $seva->getLinkedProductsList()->firstWhere('id', (int) $data['selected_product_id']);
        if (! $product) {
            return $unit;
        }

        if ($product->has_variants && ! empty($product->variants)) {
            $label = $data['selected_variant_label'] ?? null;
            $variantPrice = $label ? $product->getVariantPrice((string) $label) : null;

            return ($variantPrice !== null && $variantPrice > 0) ? (float) $variantPrice : $unit;
        }

        return ((float) $product->price > 0) ? (float) $product->price : $unit;
    }

    /** @param array<string,mixed> $data */
    private function quoteHall(array $data): array
    {
        $hall = filled($data['hall_id'] ?? null) ? Hall::find($data['hall_id']) : null;
        if ($hall === null || empty($data['hall_booking_date'])) {
            return ['total' => 0.0, 'label' => 'Hall booking', 'detail' => null, 'subtotal' => 0.0, 'gst_rate' => null, 'gst_amount' => 0.0];
        }

        [$start, $end] = $this->hallRange($data);

        // Item 4.2 pricing rule: flat price_per_day × days, computed by the
        // same service the website and the app use. Never re-derived here.
        $price = $this->halls->priceFor($hall, $start, $end);

        $detail = $price['days'].' day(s) × ₹'.number_format($price['price_per_day'], 2);
        if ($price['gst_rate'] !== null) {
            $detail .= '  +  GST '.rtrim(rtrim(number_format($price['gst_rate'], 2), '0'), '.')
                .'% ₹'.number_format($price['gst_amount'], 2);
        }

        return [
            'total' => (float) $price['total'],
            'label' => 'Hall booking — '.$hall->name,
            'detail' => $detail,
            // Carried so createHallBooking() can snapshot the same numbers
            // the counter operator was shown, rather than recomputing after
            // an admin has since edited the rate.
            'subtotal' => $price['subtotal'],
            'gst_rate' => $price['gst_rate'],
            'gst_amount' => $price['gst_amount'],
        ];
    }

    /** @return array{0:string,1:string} */
    private function hallRange(array $data): array
    {
        $start = Carbon::parse((string) $data['hall_booking_date'])->toDateString();
        $end = filled($data['hall_end_date'] ?? null)
            ? Carbon::parse((string) $data['hall_end_date'])->toDateString()
            : $start;

        if ($end < $start) {
            $end = $start;
        }

        return [$start, $end];
    }

    /** @param array<string,mixed> $data */
    private function quoteStore(array $data): array
    {
        $lines = $this->storeLines($data);
        $subtotal = array_sum(array_column($lines, 'subtotal'));

        return [
            // Counter sale — nothing to ship, so no shipping charge.
            'total' => round($subtotal, 2),
            'label' => 'Store order',
            'detail' => count($lines).' item line(s)',
        ];
    }

    /**
     * Resolve the repeater rows into priced line items. Prices come from
     * the Product row, never from the form.
     *
     * @param  array<string,mixed>  $data
     * @return list<array{product: Product, product_name: string, variant_label: ?string, quantity: int, unit_price: float, subtotal: float}>
     */
    private function storeLines(array $data): array
    {
        $lines = [];

        foreach ((array) ($data['items'] ?? []) as $row) {
            if (empty($row['product_id'])) {
                continue;
            }

            $product = Product::find($row['product_id']);
            if ($product === null) {
                continue;
            }

            $quantity = max(1, (int) ($row['quantity'] ?? 1));
            $variantLabel = filled($row['variant_label'] ?? null) ? (string) $row['variant_label'] : null;

            $unit = (float) $product->price;
            if ($product->has_variants && $variantLabel !== null) {
                $variantPrice = $product->getVariantPrice($variantLabel);
                if ($variantPrice !== null && $variantPrice > 0) {
                    $unit = (float) $variantPrice;
                }
            }

            $name = $product->name_en ?: $product->name_gu;
            if ($variantLabel !== null) {
                $name .= ' — '.$variantLabel;
            }

            $lines[] = [
                'product' => $product,
                'product_name' => $name,
                'variant_label' => $variantLabel,
                'quantity' => $quantity,
                'unit_price' => $unit,
                'subtotal' => round($unit * $quantity, 2),
            ];
        }

        return $lines;
    }

    // ── Per-type row creation (all inside the caller's transaction) ──

    /** @param array<string,mixed> $data */
    private function createDonation(array $data, Devotee $devotee, Payment $payment, array $quote, CarbonInterface $paidAt): Donation
    {
        // ── STRICT 80G (item 5.4) — identical rule to web + app ──────
        // devoteeHasValid80GPan() is the SAME gate as
        // ReceiptService::ineligibilityReason(); both funnel into
        // panIneligibilityReason(). This only records the honest verdict up
        // front — the actual minting decision is still taken inside
        // Generate80GReceipt → generateReceipt() → ineligibilityReason(),
        // so a cash donation cannot route around the gate even if this
        // pre-computation were wrong.
        $wants80g = (bool) ($data['wants_80g'] ?? true);
        $hasValidPan = $this->receipts->devoteeHasValid80GPan($devotee);
        $is80gEligible = $wants80g && $hasValidPan;

        // Gupt Daan is INDEPENDENT of the PAN (corrected 2026-08-10). It is
        // the walk-in devotee's own choice, recorded by the counter clerk's
        // Gupt Daan toggle. A cash donor without a PAN gets no 80G receipt
        // but stays a named donor on public lists. Do NOT reintroduce
        // `|| ! $hasValidPan` here.
        $anonymous = (bool) ($data['anonymous'] ?? false);

        return $this->createStamped(Donation::class, [
            'devotee_id' => $devotee->id,
            'payment_id' => $payment->id,
            'amount' => $quote['total'],
            'donation_type' => $data['donation_type'] ?? 'general',
            'donation_type_id' => $data['donation_type_id'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'campaign_id' => $data['campaign_id'] ?? null,
            'sub_cause_id' => $data['sub_cause_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'wants_80g' => $wants80g,
            'is_80g_eligible' => $is80gEligible,
            'anonymous' => $anonymous,
            // Indian FY of the DATE THE MONEY WAS RECEIVED, not of the day
            // it was typed in — a 31-March cash gift entered on 2 April
            // belongs to the closing year's receipt series.
            'financial_year' => self::financialYearFor($paidAt),
        ], $paidAt);
    }

    /** @param array<string,mixed> $data */
    private function createSevaBooking(array $data, Devotee $devotee, Payment $payment, array $quote, CarbonInterface $paidAt): SevaBooking
    {
        $seva = Seva::findOrFail($data['seva_id']);
        $date = Carbon::parse((string) $data['booking_date'])->toDateString();

        // Full-day / full-week sevas store the mode sentinel, never a time.
        $slotType = $this->slots->slotType($this->slots->configFor($seva));
        $slotTime = $slotType !== SevaSlotService::SLOT_TYPE_TIME
            ? $slotType
            : ($data['slot_time'] ?? null);

        // Race-safe capacity re-check under a row lock, exactly as the web
        // and test-mode booking paths do. A counter clerk and a devotee on
        // their phone can contend for the last slot of the day.
        if (! $this->slots->hasSlotCapacityForUpdate($seva, $date, $slotTime)) {
            throw ValidationException::withMessages([
                'booking_date' => 'That slot has just been taken. Pick another slot or date.',
            ]);
        }

        // status 'pending' — markCaptured() flips it to 'confirmed', which
        // is what fires SevaBookingObserver::updated() and therefore the
        // reminder schedule. Creating it confirmed would take the
        // observer's created() branch instead and skip the capture flip.
        return $this->createStamped(SevaBooking::class, [
            'devotee_id' => $devotee->id,
            'seva_id' => $seva->id,
            'booking_date' => $date,
            'slot_time' => $slotTime,
            'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
            'total_amount' => $quote['total'],
            'status' => 'pending',
            'payment_id' => $payment->id,
            'devotee_name_for_seva' => $data['devotee_name_for_seva'] ?? $devotee->name,
            'sankalp' => $data['sankalp'] ?? null,
            'selected_product_id' => $data['selected_product_id'] ?? null,
            'selected_variant_label' => $data['selected_variant_label'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], $paidAt);
    }

    /** @param array<string,mixed> $data */
    private function createHallBooking(array $data, Devotee $devotee, Payment $payment, array $quote, CarbonInterface $paidAt): HallBooking
    {
        $hall = Hall::findOrFail($data['hall_id']);
        [$start, $end] = $this->hallRange($data);

        // One verdict covers blackouts, the 4.3 cut-off, max_booking_days
        // and any overlapping booking across the WHOLE range (item 4.2).
        $verdict = $this->halls->checkRange($hall, $start, $end);
        if (! $verdict['ok']) {
            throw ValidationException::withMessages([
                'hall_booking_date' => $verdict['reason'] ?? 'The hall is not available for those dates.',
            ]);
        }

        if (! $this->halls->hasRangeCapacityForUpdate($hall, $start, $end)) {
            throw ValidationException::withMessages([
                'hall_booking_date' => 'Those dates have just been booked. Pick another range.',
            ]);
        }

        $days = $this->halls->priceFor($hall, $start, $end)['days'];

        return $this->createStamped(HallBooking::class, [
            'devotee_id' => $devotee->id,
            'hall_id' => $hall->id,
            'booking_date' => $start,
            'end_date' => $end,
            'days_count' => $days,
            // Full-day only since 2026-08-04; half-day values are legacy.
            'booking_type' => 'full_day',
            'purpose' => $data['hall_purpose'] ?? 'Counter booking',
            'expected_guests' => $data['expected_guests'] ?? null,
            'contact_name' => ($data['contact_name'] ?? null) ?: $devotee->name,
            'contact_phone' => substr((string) (($data['contact_phone'] ?? null) ?: $devotee->phone), 0, 15),
            'total_amount' => $quote['total'],
            'subtotal_amount' => $quote['subtotal'] ?? $quote['total'],
            'gst_rate' => $quote['gst_rate'] ?? null,
            'gst_amount' => $quote['gst_amount'] ?? 0.0,
            'status' => 'pending',
            'payment_id' => $payment->id,
            'admin_notes' => $data['notes'] ?? null,
        ], $paidAt);
    }

    /** @param array<string,mixed> $data */
    private function createOrder(array $data, Devotee $devotee, Payment $payment, array $quote, CarbonInterface $paidAt): Order
    {
        $lines = $this->storeLines($data);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'items' => 'Add at least one product to the order.',
            ]);
        }

        // Inclusive GST — decomposes the counter sale, never adds to it, so
        // the cash the karyakar takes is exactly the quote shown on screen.
        $tax = app(StoreGstService::class)->decompose($lines);
        $lines = $tax['lines'];

        $order = $this->createStamped(Order::class, array_merge([
            'devotee_id' => $devotee->id,
            'payment_id' => $payment->id,
            'subtotal' => $quote['total'],
            'taxable_amount' => $tax['taxable_amount'],
            'gst_amount' => $tax['gst_amount'],
            'shipping_charge' => 0,
            'total_amount' => $quote['total'],
            'status' => 'pending',
            'notes' => $data['notes'] ?? null,
        ], $this->counterShipping($devotee, $data)), $paidAt);

        foreach ($lines as $line) {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $line['product']->id,
                'product_name' => $line['product_name'],
                'variant_label' => $line['variant_label'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'subtotal' => $line['subtotal'],
                'gst_rate' => $line['gst_rate'] ?? null,
                'gst_amount' => $line['gst_amount'] ?? null,
            ]);
            // Stock is NOT decremented here — markCaptured() owns that, so
            // the counter sale gets the same variant-aware, id-ordered,
            // row-locked decrement (with its oversell logging) as an
            // online order. Decrementing here would double-count.
        }

        return $order;
    }

    /**
     * A walk-in prasad sale has no shipping address, but all six
     * `shipping_*` columns are NOT NULL. Defaulting them beats migrating
     * a live money table: GenerateStoreInvoice and the packing-slip Blade
     * keep rendering unchanged, and the fields stay editable in case the
     * devotee does want it posted.
     *
     * The address line is rendered in the DEVOTEE'S language, because it
     * is printed on the invoice PDF that they receive.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,string>
     */
    private function counterShipping(Devotee $devotee, array $data): array
    {
        $counterAddress = DevoteeLocale::withLocale(
            DevoteeLocale::for($devotee),
            fn (): string => (string) __('counter.sale_address'),
        );

        return [
            'shipping_name' => (string) (($data['shipping_name'] ?? null) ?: $devotee->name),
            'shipping_phone' => (string) (($data['shipping_phone'] ?? null) ?: $devotee->phone),
            'shipping_address' => (string) (($data['shipping_address'] ?? null) ?: $counterAddress),
            'shipping_city' => (string) (($data['shipping_city'] ?? null) ?: SystemSetting::getValue('trust_city', 'Antarjal')),
            'shipping_state' => (string) (($data['shipping_state'] ?? null) ?: SystemSetting::getValue('trust_state', 'Gujarat')),
            'shipping_pincode' => (string) (($data['shipping_pincode'] ?? null) ?: SystemSetting::getValue('trust_pincode', '370205')),
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Create a row dated when the money actually changed hands.
     *
     * `created_at`, not just `payment.paid_at`, and NOT via
     * Model::create([... 'created_at' => …]) — the timestamp columns are
     * absent from every one of these models' $fillable, so a mass-assigned
     * created_at is silently dropped and Eloquent stamps now() anyway. It
     * has to be set on the instance before save(), where updateTimestamps()
     * sees it as dirty and leaves it alone.
     *
     * Why created_at matters and paid_at alone does not: ReceiptService
     * prints `donation.created_at` as the statutory donation date, the
     * financial-year series is derived from the same moment, and every
     * period report on the platform groups on created_at. A back-date that
     * only moved paid_at would be invisible in all three.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $class
     * @param  array<string,mixed>  $attributes
     * @return TModel
     */
    private function createStamped(string $class, array $attributes, CarbonInterface $paidAt): Model
    {
        $model = new $class($attributes);
        $model->created_at = $paidAt;
        $model->updated_at = $paidAt;
        $model->save();

        return $model;
    }

    /**
     * Indian financial year (April–March) as "YYYY-YY".
     * Public + static so the counter form, the tests and any future
     * back-dating caller all agree on one definition.
     */
    public static function financialYearFor(CarbonInterface $moment): string
    {
        return $moment->month >= 4
            ? $moment->year.'-'.substr((string) ($moment->year + 1), -2)
            : ($moment->year - 1).'-'.substr((string) $moment->year, -2);
    }

    /**
     * The moment the money changed hands.
     *
     * Today (or blank) → now(), so the overwhelmingly common case is
     * indistinguishable from an online capture. An earlier date → noon on
     * that date, a neutral time that cannot drift across a day boundary
     * through a timezone conversion. A FUTURE date is refused: cash that
     * has not been handed over yet is not a captured payment.
     */
    private function resolvePaidAt(mixed $paidOn): CarbonInterface
    {
        if (blank($paidOn)) {
            return now();
        }

        $date = $paidOn instanceof CarbonInterface
            ? Carbon::instance($paidOn)
            : Carbon::parse((string) $paidOn);

        if ($date->isFuture() && ! $date->isSameDay(now())) {
            throw ValidationException::withMessages([
                'paid_on' => 'A counter payment cannot be dated in the future.',
            ]);
        }

        return $date->isSameDay(now()) ? now() : $date->copy()->setTime(12, 0);
    }

    /** Human trace that survives even if created_by_admin_id is NULLed. */
    private function describeEntry(array $data, array $quote, AdminUser $admin): string
    {
        $reference = filled($data['reference_note'] ?? null) ? ' ref '.$data['reference_note'] : '';

        return Str::limit(
            "Counter entry — {$quote['label']}{$reference} (admin: {$admin->email})",
            490,
            '',
        );
    }

    /**
     * A replayed submission. Returns the record already attached to the
     * winning Payment so the UI can show the same confirmation instead of
     * creating a second one.
     *
     * @return array{payment: Payment, record: Model, devotee: Devotee, type: string, label: string, duplicate: bool}
     */
    private function describeExisting(Payment $payment, string $type): array
    {
        $record = match ($type) {
            self::TYPE_DONATION => $payment->donation,
            self::TYPE_SEVA => $payment->sevaBooking,
            self::TYPE_HALL => $payment->hallBooking,
            self::TYPE_STORE => $payment->order,
            default => null,
        };

        return [
            'payment' => $payment,
            'record' => $record,
            'devotee' => $record?->devotee,
            'type' => $type,
            'label' => (string) $payment->description,
            'duplicate' => true,
        ];
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1062;
    }
}
