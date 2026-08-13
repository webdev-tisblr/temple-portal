<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UnavailableReason;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place hall availability, conflicts, cut-off and pricing are
 * decided (items 4.1 – 4.4, 2026-08-09).
 *
 * Before this, the logic was copy-pasted across four controllers, the
 * conflict check ran OUTSIDE the booking transaction with no row lock
 * (so two devotees could both pay for the same date — sevas were
 * protected, halls were not), and hall capacity queries used
 * ['pending','confirmed'] while sevas used
 * ['pending','confirmed','completed'].
 *
 * Ranges are CLOSED intervals: a 12th → 14th booking occupies the 12th,
 * 13th AND 14th, and costs 3 × price_per_day. No changeover day.
 */
final class HallAvailabilityService
{
    /**
     * Statuses that occupy a hall. `completed` was missing on the hall side
     * — unified with the seva rule here.
     *
     * @var list<string>
     */
    public const OCCUPYING = HallBooking::OCCUPYING_STATUSES;

    /** Hard ceiling so a crafted request can't ask for a 10-year range. */
    public const MAX_RANGE_DAYS = 90;

    /**
     * Bookings of this hall whose date range overlaps [$start, $end].
     * Optionally excludes one booking id (for admin edits).
     */
    public function conflictsQuery(Hall $hall, string $start, string $end, ?int $exceptId = null): Builder
    {
        $q = HallBooking::query()
            ->where('hall_id', $hall->id)
            ->whereIn('status', self::OCCUPYING)
            ->overlapping($start, $end);

        if ($exceptId !== null) {
            $q->where('id', '!=', $exceptId);
        }

        return $q;
    }

    /**
     * Full verdict for a requested range: max_booking_days, past dates,
     * the cut-off, per-day blackouts and overlapping bookings.
     *
     * @return array{ok:bool, reason_code:?string, reason:?string, days:int, conflicting_dates:list<string>}
     */
    public function checkRange(Hall $hall, string $start, string $end): array
    {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();

        if ($endDate->lt($startDate)) {
            return $this->verdict(false, UnavailableReason::RangeTooLong, 0, [], (string) __('availability.hall_range_invalid'));
        }

        $days = (int) $startDate->diffInDays($endDate) + 1;

        $max = min($hall->maxBookingDays(), self::MAX_RANGE_DAYS);
        if ($days > $max) {
            return $this->verdict(
                false,
                UnavailableReason::RangeTooLong,
                $days,
                [],
                (string) __('availability.hall_range_too_long', ['max' => $max]),
            );
        }

        if ($startDate->lt(now()->startOfDay())) {
            return $this->verdict(false, UnavailableReason::PastDate, $days, [$startDate->toDateString()]);
        }

        // Cut-off applies to the range START only — interior days are by
        // definition further in the future (item 4.3).
        if (($cutoff = $this->cutoffReason($hall, $startDate->toDateString())) !== null) {
            return $this->verdict(
                false,
                $cutoff,
                $days,
                [$startDate->toDateString()],
                (string) __('availability.cutoff_blocked', ['hours' => $hall->bookingCutoffHours()]),
            );
        }

        // Admin blockout wins over everything, per day of the range.
        for ($cursor = $startDate->copy(); $cursor->lte($endDate); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $blackout = $hall->blackoutReason($date);
            if ($blackout !== null) {
                return $this->verdict(
                    false,
                    UnavailableReason::Blackout,
                    $days,
                    [$date],
                    $blackout !== '' ? $blackout : null,
                );
            }
        }

        $conflicts = $this->conflictingDates($hall, $startDate->toDateString(), $endDate->toDateString());
        if ($conflicts !== []) {
            return $this->verdict(
                false,
                UnavailableReason::HallBooked,
                $days,
                $conflicts,
                (string) __('availability.hall_range_conflict', ['dates' => implode(', ', $conflicts)]),
            );
        }

        return $this->verdict(true, null, $days, []);
    }

    /**
     * The individual dates inside [$start, $end] already taken by an
     * occupying booking (possibly a multi-day one).
     *
     * @return list<string>
     */
    public function conflictingDates(Hall $hall, string $start, string $end): array
    {
        $startDate = Carbon::parse($start)->startOfDay();
        $endDate = Carbon::parse($end)->startOfDay();

        $taken = [];
        foreach ($this->conflictsQuery($hall, $start, $end)->get(['booking_date', 'end_date']) as $booking) {
            $from = $booking->booking_date->copy()->max($startDate);
            $to = $booking->rangeEnd()->min($endDate);
            for ($cursor = $from->copy(); $cursor->lte($to); $cursor->addDay()) {
                $taken[$cursor->toDateString()] = true;
            }
        }

        $dates = array_keys($taken);
        sort($dates);

        return $dates;
    }

    /**
     * Race-safe re-check, MUST be called INSIDE the booking transaction.
     *
     * Mirrors SevaSlotService::hasSlotCapacityForUpdate: the SELECT … FOR
     * UPDATE takes InnoDB row/gap locks on (hall_id, booking_date, …), so
     * two devotees booking the same range serialise — the second blocks
     * until the first commits, then sees the row and is rejected. This
     * closes the pre-existing hall double-booking race.
     */
    public function hasRangeCapacityForUpdate(Hall $hall, string $start, string $end): bool
    {
        return ! $this->conflictsQuery($hall, $start, $end)->lockForUpdate()->exists();
    }

    /**
     * Server-authoritative pricing: flat price_per_day × days, the whole
     * range blocked. The client NEVER computes this.
     *
     * THE one place hall money is calculated. All four booking entry points
     * (web, API, web test-mode, admin counter entry) call this, so GST added
     * here reaches every one of them — which is exactly why it was added
     * here in 2026-08-12 rather than in the controllers.
     *
     * GST IS INCLUSIVE (changed 2026-08-13; it was added on top until then).
     * The hall's advertised day rate IS what the devotee pays, and tax is
     * carved OUT of it for the invoice — so the trust nets less per booking
     * than the sticker price, which is the intended trade for a price that
     * needs no asterisk.
     *
     * That direction is load-bearing for rounding: `total` is computed
     * first, from the rate the devotee was quoted, and `subtotal` is derived
     * from it. Deriving the total from a rounded taxable value instead would
     * drift it a paisa off the advertised price.
     *
     * `total` stays the GROSS payable, so callers that hand it to Razorpay
     * or persist it as total_amount need no change; `subtotal` and
     * `gst_amount` decompose it for the invoice.
     *
     * @return array{days:int, price_per_day:float, subtotal:float, gst_rate:float|null, gst_amount:float, total:float}
     */
    public function priceFor(Hall $hall, string $start, string $end): array
    {
        $days = max(1, (int) Carbon::parse($start)->startOfDay()->diffInDays(Carbon::parse($end)->startOfDay()) + 1);
        $perDay = (float) $hall->price_per_day;
        $total = round($perDay * $days, 2);

        $gstRate = $this->gstRateFor($hall);

        // Tax is the REMAINDER of the total, never a second rounded
        // computation — taxable + GST must equal the amount charged to the
        // paisa or the invoice does not reconcile against the payment.
        $subtotal = $gstRate === null
            ? $total
            : round($total / (1 + $gstRate / 100), 2);
        $gstAmount = round($total - $subtotal, 2);

        return [
            'days' => $days,
            'price_per_day' => $perDay,
            'subtotal' => $subtotal,
            'gst_rate' => $gstRate,
            'gst_amount' => $gstAmount,
            'total' => $total,
        ];
    }

    /**
     * Effective GST percentage for a hall, or NULL when tax does not apply.
     *
     * Order: the master switch, then the hall's own override, then the
     * trust-wide default. A hall may legitimately be taxed differently from
     * the rest (a commercial banquet rate vs a community hall), so the
     * per-hall column wins when set.
     *
     * NULL — not 0.0 — is the "no GST" answer, so the booking row records
     * "untaxed" rather than the false claim "taxed at 0%".
     */
    public function gstRateFor(Hall $hall): ?float
    {
        if (SystemSetting::getValue('hall_gst_enabled', '0') !== '1') {
            return null;
        }

        $rate = $hall->gst_rate !== null
            ? (float) $hall->gst_rate
            : (float) SystemSetting::getValue('hall_gst_rate', '0');

        return $rate > 0 ? round($rate, 2) : null;
    }

    /**
     * Per-date availability for one calendar month — item 4.1. Every date
     * is returned (never omitted); unbookable ones carry a reason_code.
     *
     * @return list<array{date:string, available:bool, reason_code:?string, reason:?string}>
     */
    public function monthAvailability(Hall $hall, string $month): array
    {
        $monthStart = Carbon::createFromFormat('!Y-m', $month);
        $start = $monthStart->copy()->max(now()->startOfDay());
        $end = $monthStart->copy()->endOfMonth()->startOfDay();

        if ($end->lt($start)) {
            return [];
        }

        return $this->rangeAvailability($hall, $start, $end);
    }

    /**
     * Per-date availability across an arbitrary window. One query covers
     * the whole window; each date is then resolved in memory.
     *
     * @return list<array{date:string, available:bool, reason_code:?string, reason:?string}>
     */
    public function rangeAvailability(Hall $hall, Carbon $start, Carbon $end): array
    {
        $taken = array_flip($this->conflictingDates($hall, $start->toDateString(), $end->toDateString()));

        $out = [];
        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $date = $cursor->toDateString();

            $reason = null;
            $text = null;

            $blackout = $hall->blackoutReason($date);
            if ($blackout !== null) {
                $reason = UnavailableReason::Blackout;
                $text = $blackout !== '' ? $blackout : null;
            } elseif (isset($taken[$date])) {
                $reason = UnavailableReason::HallBooked;
            } elseif (($cut = $this->cutoffReason($hall, $date)) !== null) {
                $reason = $cut;
            }

            $out[] = [
                'date' => $date,
                'available' => $reason === null,
                'reason_code' => $reason?->value,
                'reason' => $reason === null ? null : ($text ?? $reason->label()),
                // `blocked` in the API means "admin blockout", NOT "booked" —
                // preserved separately so the shipped app keeps its meaning.
                'blackout_reason' => $blackout,
            ];
        }

        return $out;
    }

    /**
     * First window of $days consecutive bookable dates from $from — item 4.4.
     *
     * @return array{date:string, end_date:string, days:int}|null
     */
    public function nextAvailable(Hall $hall, ?string $from = null, int $horizonDays = 365, int $days = 1): ?array
    {
        $today = now()->startOfDay();
        $start = $from ? Carbon::parse($from)->startOfDay() : $today->copy();
        if ($start->lt($today)) {
            $start = $today->copy();
        }

        $days = max(1, min($days, min($hall->maxBookingDays(), self::MAX_RANGE_DAYS)));
        $horizonDays = max(1, min($horizonDays, 730));
        $end = $start->copy()->addDays($horizonDays - 1);

        $rows = $this->rangeAvailability($hall, $start, $end);

        $run = 0;
        foreach ($rows as $i => $row) {
            if ($row['available'] !== true) {
                $run = 0;

                continue;
            }
            $run++;
            if ($run >= $days) {
                $startIdx = $i - $days + 1;

                return [
                    'date' => $rows[$startIdx]['date'],
                    'end_date' => $row['date'],
                    'days' => $days,
                ];
            }
        }

        return null;
    }

    /**
     * Cut-off verdict for a hall date (item 4.3). Halls have no slot time,
     * so the anchor is the per-hall `day_start_time` (default 09:00).
     */
    public function cutoffReason(Hall $hall, string $date): ?UnavailableReason
    {
        $hours = $hall->bookingCutoffHours();
        if ($hours <= 0) {
            return null;
        }

        return $hall->dayStartMoment($date)->lessThan(now()->addHours($hours))
            ? UnavailableReason::Cutoff
            : null;
    }

    /**
     * @param  list<string>  $conflicts
     * @return array{ok:bool, reason_code:?string, reason:?string, days:int, conflicting_dates:list<string>}
     */
    private function verdict(bool $ok, ?UnavailableReason $reason, int $days, array $conflicts, ?string $text = null): array
    {
        return [
            'ok' => $ok,
            'reason_code' => $reason?->value,
            'reason' => $reason === null ? null : ($text ?? $reason->label()),
            'days' => $days,
            'conflicting_dates' => $conflicts,
        ];
    }
}
