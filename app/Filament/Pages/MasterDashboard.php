<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Donation;
use App\Models\HallBooking;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Services\SevaSlotService;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One screen that answers the questions the trust actually asks: what is on
 * today, how are donations trending, how full are the sevas, what is coming
 * up, and what does the month look like on paper.
 *
 * Separate from /admin's dashboard widgets on purpose. Those are a fixed
 * at-a-glance strip; this is interrogable — pick a day, pick a range, page
 * through months, print the result for a trustees' meeting.
 *
 * TWO RULES THAT RUN THROUGH EVERY QUERY HERE
 * -------------------------------------------
 *  • Money is counted only when CAPTURED. An abandoned Razorpay handshake
 *    leaves a `pending` row that never became money; counting it would
 *    overstate every total on the page. Same guard the existing widgets use.
 *  • Bookings count as taken when pending, confirmed or completed —
 *    `pending` holds a slot during the payment window, so for "is this slot
 *    free" it is occupied. That is the same set SevaSlotService uses, so
 *    this page and the booking screens can never disagree.
 */
class MasterDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Master dashboard';

    protected static ?string $title = 'Master dashboard';

    protected static ?int $navigationSort = -10;

    protected static string $view = 'filament.pages.master-dashboard';

    /** Statuses that mean a slot is spoken for. */
    private const HELD = ['pending', 'confirmed', 'completed'];

    public string $snapshotDate = '';

    public string $rangeStart = '';

    public string $rangeEnd = '';

    /** First day of the month the calendar is showing. */
    public string $calendarMonth = '';

    /** How far ahead "nearing" looks. */
    public int $nearingDays = 14;

    public function mount(): void
    {
        $today = Carbon::today();

        $this->snapshotDate = $today->toDateString();
        $this->rangeStart = $today->copy()->subDays(29)->toDateString();
        $this->rangeEnd = $today->toDateString();
        $this->calendarMonth = $today->copy()->startOfMonth()->toDateString();
    }

    public function previousMonth(): void
    {
        $this->calendarMonth = Carbon::parse($this->calendarMonth)->subMonthNoOverflow()->startOfMonth()->toDateString();
    }

    public function nextMonth(): void
    {
        $this->calendarMonth = Carbon::parse($this->calendarMonth)->addMonthNoOverflow()->startOfMonth()->toDateString();
    }

    public function thisMonth(): void
    {
        $this->calendarMonth = Carbon::today()->startOfMonth()->toDateString();
    }

    /**
     * 1 — everything happening on ONE day.
     *
     * Hall bookings are matched by RANGE, not by start date: a three-day
     * booking is "on" its middle day too, which a booking_date = ? filter
     * would miss.
     *
     * @return array{sevas: Collection<int, SevaBooking>, halls: Collection<int, HallBooking>}
     */
    public function getDaySnapshotProperty(): array
    {
        $date = $this->safeDate($this->snapshotDate) ?? Carbon::today()->toDateString();

        return [
            'sevas' => SevaBooking::query()
                ->with(['seva', 'devotee'])
                ->whereDate('booking_date', $date)
                ->whereIn('status', self::HELD)
                ->orderBy('slot_time')
                ->get(),
            'halls' => HallBooking::query()
                ->with(['hall', 'devotee'])
                ->where('booking_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->whereIn('status', self::HELD)
                ->orderBy('booking_date')
                ->get(),
        ];
    }

    /**
     * 2 — donations across the chosen range.
     *
     * min/max are per DONATION, not per day: "what is the smallest and
     * largest single offering" is the question a trustee actually asks.
     * The daily series underneath is what gets drawn.
     *
     * @return array<string, mixed>
     */
    public function getDonationStatsProperty(): array
    {
        [$start, $end] = $this->range();

        $base = fn (): Builder => Donation::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'))
            ->whereBetween('created_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]);

        $agg = $base()
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount),0) AS total, COALESCE(AVG(amount),0) AS avg, COALESCE(MIN(amount),0) AS min, COALESCE(MAX(amount),0) AS max')
            ->first();

        $daily = $base()
            ->selectRaw('DATE(created_at) AS d, COALESCE(SUM(amount),0) AS total, COUNT(*) AS n')
            ->groupBy('d')
            ->pluck('total', 'd');

        // Zero-fill: a day with no donations must still occupy width on the
        // chart, or a quiet week silently compresses and the shape lies.
        $series = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();
            $series[$key] = (float) ($daily[$key] ?? 0);
        }

        return [
            'count' => (int) ($agg->n ?? 0),
            'total' => (float) ($agg->total ?? 0),
            'average' => (float) ($agg->avg ?? 0),
            'min' => (float) ($agg->min ?? 0),
            'max' => (float) ($agg->max ?? 0),
            'series' => $series,
            'peak' => $series === [] ? 0.0 : max($series),
            'days' => count($series),
        ];
    }

    /**
     * 3 — how full each seva is ON THE SNAPSHOT DATE.
     *
     * Asked of SevaSlotService rather than counted from bookings, because
     * "available" is not a fixed number: it depends on the weekly schedule,
     * blackout dates, the slot pool a seva belongs to and the booking
     * cut-off. Only the service knows all of that, and asking it here is
     * what stops this page claiming a slot is free while the booking screen
     * refuses it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSlotUtilisationProperty(): array
    {
        $date = $this->safeDate($this->snapshotDate) ?? Carbon::today()->toDateString();
        $service = app(SevaSlotService::class);
        $rows = [];

        foreach (Seva::where('is_active', true)->orderBy('sort_order')->orderBy('id')->get() as $seva) {
            $availability = $service->getSlotAvailability($seva, $date);

            $free = count($availability['available'] ?? []);
            $taken = count($availability['booked'] ?? []);
            $total = $free + $taken;

            // A seva that offers nothing that day (wrong weekday, blackout,
            // outside its acceptance window) is not 0% booked — it is simply
            // not on. Showing it as an empty progress bar reads as neglect.
            if ($total === 0) {
                $rows[] = [
                    'seva' => $seva->name_en ?: $seva->name_gu,
                    'offered' => false,
                    'reason' => $availability['blackout'] ?? false ? ($availability['blackout_reason'] ?: 'Blacked out') : 'Not offered on this date',
                    'taken' => 0, 'total' => 0, 'pct' => 0,
                ];

                continue;
            }

            $rows[] = [
                'seva' => $seva->name_en ?: $seva->name_gu,
                'offered' => true,
                'reason' => null,
                'taken' => $taken,
                'total' => $total,
                'pct' => (int) round($taken / $total * 100),
            ];
        }

        return $rows;
    }

    /**
     * 4 — what is coming up, soonest first.
     *
     * Deliberately starts from TODAY rather than tomorrow: something later
     * today is the most urgent thing on the page, and dropping it is exactly
     * how a seva gets missed.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function getNearingBookingsProperty(): Collection
    {
        $from = Carbon::today();
        $to = $from->copy()->addDays(max(1, $this->nearingDays));

        $sevas = SevaBooking::query()
            ->with(['seva', 'devotee'])
            ->whereBetween('booking_date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('status', self::HELD)
            ->get()
            ->map(fn (SevaBooking $b): array => [
                'date' => $b->booking_date,
                'kind' => 'Seva',
                'what' => $b->seva?->name_en ?: $b->seva?->name_gu ?: '—',
                'detail' => $b->slot_time === 'full_day' ? 'Whole day' : (string) $b->slot_time,
                'who' => $b->devotee_name_for_seva ?: ($b->devotee?->name ?? '—'),
                'amount' => (float) $b->total_amount,
            ]);

        $halls = HallBooking::query()
            ->with(['hall', 'devotee'])
            ->where('end_date', '>=', $from->toDateString())
            ->where('booking_date', '<=', $to->toDateString())
            ->whereIn('status', self::HELD)
            ->get()
            ->map(fn (HallBooking $b): array => [
                'date' => $b->booking_date,
                'kind' => 'Hall',
                'what' => $b->hall?->getAttributes()['name'] ?? '—',
                'detail' => $b->days_count > 1 ? $b->days_count.' days' : 'Single day',
                'who' => $b->contact_name ?: ($b->devotee?->name ?? '—'),
                'amount' => (float) $b->total_amount,
            ]);

        return $sevas->concat($halls)->sortBy('date')->values();
    }

    /**
     * 5 — the month grid, plus the printable list for the chosen range.
     *
     * @return array<string, mixed>
     */
    public function getCalendarProperty(): array
    {
        $monthStart = Carbon::parse($this->safeDate($this->calendarMonth) ?? Carbon::today()->toDateString())->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        $counts = [];

        foreach (SevaBooking::query()
            ->whereBetween('booking_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->whereIn('status', self::HELD)
            ->get(['booking_date']) as $b) {
            $counts[$b->booking_date->toDateString()]['seva'] = ($counts[$b->booking_date->toDateString()]['seva'] ?? 0) + 1;
        }

        // A hall booking occupies every day it spans, so it is counted onto
        // each one — the grid answers "is the hall busy that day", and a
        // three-day wedding is busy on all three.
        foreach (HallBooking::query()
            ->where('end_date', '>=', $monthStart->toDateString())
            ->where('booking_date', '<=', $monthEnd->toDateString())
            ->whereIn('status', self::HELD)
            ->get(['booking_date', 'end_date']) as $b) {
            $day = $b->booking_date->copy()->max($monthStart);
            $last = $b->end_date->copy()->min($monthEnd);
            for (; $day->lte($last); $day->addDay()) {
                $counts[$day->toDateString()]['hall'] = ($counts[$day->toDateString()]['hall'] ?? 0) + 1;
            }
        }

        // Pad to whole weeks so the grid is rectangular. Monday-first.
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $weeks = [];
        $week = [];
        for ($day = $gridStart->copy(); $day->lte($gridEnd); $day->addDay()) {
            $key = $day->toDateString();
            $week[] = [
                'date' => $key,
                'day' => $day->day,
                'inMonth' => $day->month === $monthStart->month,
                'isToday' => $day->isToday(),
                'seva' => $counts[$key]['seva'] ?? 0,
                'hall' => $counts[$key]['hall'] ?? 0,
            ];
            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        return [
            'label' => $monthStart->format('F Y'),
            'weeks' => $weeks,
            'printList' => $this->bookingsInRange(),
        ];
    }

    /**
     * Every booking in the chosen range, flattened and sorted — this is what
     * the print button puts on paper.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function bookingsInRange(): Collection
    {
        [$start, $end] = $this->range();

        $sevas = SevaBooking::query()
            ->with(['seva', 'devotee'])
            ->whereBetween('booking_date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('status', self::HELD)
            ->get()
            ->map(fn (SevaBooking $b): array => [
                'date' => $b->booking_date?->format('d M Y') ?? '—',
                'sort' => $b->booking_date?->toDateString() ?? '',
                'kind' => 'Seva',
                'what' => $b->seva?->name_en ?: $b->seva?->name_gu ?: '—',
                'detail' => $b->slot_time === 'full_day' ? 'Whole day' : (string) $b->slot_time,
                'who' => $b->devotee_name_for_seva ?: ($b->devotee?->name ?? '—'),
                'phone' => (string) ($b->devotee?->phone ?? ''),
                'amount' => (float) $b->total_amount,
                'status' => (string) ($b->status instanceof \BackedEnum ? $b->status->value : $b->status),
            ]);

        $halls = HallBooking::query()
            ->with(['hall', 'devotee'])
            ->where('end_date', '>=', $start->toDateString())
            ->where('booking_date', '<=', $end->toDateString())
            ->whereIn('status', self::HELD)
            ->get()
            ->map(fn (HallBooking $b): array => [
                'date' => $b->days_count > 1
                    ? ($b->booking_date?->format('d M') ?? '—').' – '.($b->end_date?->format('d M Y') ?? '')
                    : ($b->booking_date?->format('d M Y') ?? '—'),
                'sort' => $b->booking_date?->toDateString() ?? '',
                'kind' => 'Hall',
                'what' => $b->hall?->getAttributes()['name'] ?? '—',
                'detail' => $b->days_count > 1 ? $b->days_count.' days' : 'Single day',
                'who' => $b->contact_name ?: ($b->devotee?->name ?? '—'),
                'phone' => (string) ($b->contact_phone ?? ''),
                'amount' => (float) $b->total_amount,
                'status' => (string) $b->status,
            ]);

        return $sevas->concat($halls)->sortBy('sort')->values();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(): array
    {
        $start = Carbon::parse($this->safeDate($this->rangeStart) ?? Carbon::today()->subDays(29)->toDateString());
        $end = Carbon::parse($this->safeDate($this->rangeEnd) ?? Carbon::today()->toDateString());

        // A backwards range returns nothing and looks like a bug rather than
        // a typo, so swap instead.
        return $start->lte($end) ? [$start, $end] : [$end, $start];
    }

    /** Reject anything that is not a plain date — these are user-typed. */
    private function safeDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) ? $raw : null;
    }
}
