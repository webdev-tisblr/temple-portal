<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\SevaBookingResource;
use App\Models\SevaBooking;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

/**
 * Daily seva-booking count over the last 30 days. Mirrors DonationChart
 * in shape (line, half-width, captured-only) so the two charts read
 * as a matched pair on the dashboard — one for ₹, one for bookings.
 *
 * Captured-only is the same guard the rest of the dashboard uses:
 * abandoned Razorpay handshakes inflate raw row counts and have to
 * be filtered out via payment.status='captured'.
 */
class SevaBookingsChart extends ChartWidget
{
    protected static ?string $heading = 'Seva bookings · last 30 days';

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_SevaBookingsChart') ?? false;
    }

    public function getHeading(): string | Htmlable | null
    {
        $url = e(SevaBookingResource::getUrl());
        return new HtmlString(
            "<a href=\"{$url}\" class=\"hover:underline\" style=\"color: inherit;\">Seva bookings · last 30 days →</a>"
        );
    }

    protected function getData(): array
    {
        $days = collect(CarbonPeriod::create(now()->subDays(29), now()));

        // Grouped by booking_date (not created_at) so the chart shows
        // the day the seva is/was performed, which is what the temple
        // actually cares about operationally.
        $bookings = SevaBooking::selectRaw('DATE(booking_date) as date, COUNT(*) as total')
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'))
            ->where('booking_date', '>=', now()->subDays(30)->toDateString())
            ->groupByRaw('DATE(booking_date)')
            ->pluck('total', 'date');

        return [
            'datasets' => [
                [
                    'label' => 'Bookings',
                    'data' => $days->map(fn ($day) => (int) ($bookings[$day->format('Y-m-d')] ?? 0))->toArray(),
                    'borderColor' => '#C89434',
                    'backgroundColor' => 'rgba(200, 148, 52, 0.12)',
                    'fill' => true,
                ],
            ],
            'labels' => $days->map(fn ($day) => $day->format('d M'))->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
