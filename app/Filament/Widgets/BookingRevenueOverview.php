<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\HallBookingResource;
use App\Filament\Resources\SevaBookingResource;
use App\Models\HallBooking;
use App\Models\SevaBooking;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Seva + hall booking money, in the same shape as DonationStatsOverview.
 * Donations were the only revenue stream on the dashboard, so the trust
 * had no way to see what seva and hall bookings brought in without
 * opening each resource and adding it up by eye.
 *
 * Two guards, both deliberate:
 *  • captured-only — an abandoned Razorpay checkout leaves a Payment row
 *    behind; counting it inflates every tile. Same guard as
 *    DonationStatsOverview / OperationsTodayOverview / FinancialReports.
 *  • cancelled + refunded bookings are excluded. Unlike a donation, a
 *    booking can be handed back after capture; the money is no longer the
 *    trust's, so it must not sit in a tile labelled "revenue".
 *
 * Dates are created_at (when the money was taken), NOT booking_date (when
 * the seva is performed / the hall is used) — this row answers "what came
 * in", which is the question the donation row above it answers too.
 */
class BookingRevenueOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getHeading(): ?string
    {
        return 'Booking revenue';
    }

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_BookingRevenueOverview') ?? false;
    }

    protected function getStats(): array
    {
        $now = CarbonImmutable::now();
        $fyStartYear = $now->month >= 4 ? $now->year : $now->year - 1;
        $fyStart = CarbonImmutable::create($fyStartYear, 4, 1)->startOfDay();
        $fyEnd = CarbonImmutable::create($fyStartYear + 1, 3, 31)->endOfDay();
        $fyLabel = $fyStartYear.'-'.substr((string) ($fyStartYear + 1), -2);

        $seva = $this->totals(SevaBooking::class, $fyStart, $fyEnd);
        $hall = $this->totals(HallBooking::class, $fyStart, $fyEnd);

        $sevaUrl = SevaBookingResource::getUrl();
        $hallUrl = HallBookingResource::getUrl();

        return [
            Stat::make('Seva today', '₹'.number_format($seva['today_sum']))
                ->description("{$seva['today_count']} bookings today")
                ->color('success')
                ->url($sevaUrl),
            Stat::make('Seva this month', '₹'.number_format($seva['month_sum']))
                ->description("{$seva['month_count']} bookings · MTD")
                ->color('primary')
                ->url($sevaUrl),
            Stat::make("Seva FY {$fyLabel}", '₹'.number_format($seva['fy_sum']))
                ->description("{$seva['fy_count']} bookings · YTD")
                ->color('warning')
                ->url($sevaUrl),

            Stat::make('Hall today', '₹'.number_format($hall['today_sum']))
                ->description("{$hall['today_count']} bookings today")
                ->color('success')
                ->url($hallUrl),
            Stat::make('Hall this month', '₹'.number_format($hall['month_sum']))
                ->description("{$hall['month_count']} bookings · MTD")
                ->color('primary')
                ->url($hallUrl),
            Stat::make("Hall FY {$fyLabel}", '₹'.number_format($hall['fy_sum']))
                ->description("{$hall['fy_count']} bookings · YTD")
                ->color('warning')
                ->url($hallUrl),
        ];
    }

    /**
     * @param  class-string<SevaBooking|HallBooking>  $model
     * @return array{today_sum: float, today_count: int, month_sum: float, month_count: int, fy_sum: float, fy_count: int}
     */
    private function totals(string $model, CarbonImmutable $fyStart, CarbonImmutable $fyEnd): array
    {
        $earned = fn (): Builder => $model::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'))
            ->whereNotIn('status', ['cancelled', 'refunded']);

        // One aggregate per window instead of a sum() + count() pair, so
        // the widget costs three queries per model rather than six.
        $window = function (Builder $query): array {
            $row = $query->selectRaw('COALESCE(SUM(total_amount), 0) as sum_amount, COUNT(*) as row_count')->first();

            return [
                'sum' => (float) ($row->sum_amount ?? 0),
                'count' => (int) ($row->row_count ?? 0),
            ];
        };

        $today = $window($earned()->whereDate('created_at', today()));
        $month = $window($earned()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year));
        $fy = $window($earned()->whereBetween('created_at', [$fyStart, $fyEnd]));

        return [
            'today_sum' => $today['sum'],
            'today_count' => $today['count'],
            'month_sum' => $month['sum'],
            'month_count' => $month['count'],
            'fy_sum' => $fy['sum'],
            'fy_count' => $fy['count'],
        ];
    }
}
