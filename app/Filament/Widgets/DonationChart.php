<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\DonationResource;
use App\Models\Donation;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class DonationChart extends ChartWidget
{
    protected static ?string $heading = 'Donations · last 30 days';

    protected static ?int $sort = 5;

    // Half-width on the default 2-column dashboard grid so this sits
    // beside the SevaBookings chart. Filament collapses both to full
    // width below the md breakpoint.
    protected int|string|array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_DonationChart') ?? false;
    }

    // Override the heading to be an anchor — Filament renders this
    // string verbatim inside the chart card header, so the whole
    // title becomes a click-through to the Donations list. Cheaper
    // than a custom view; matches the click-anywhere stat pattern.
    public function getHeading(): string | Htmlable | null
    {
        $url = e(DonationResource::getUrl());
        return new HtmlString(
            "<a href=\"{$url}\" class=\"hover:underline\" style=\"color: inherit;\">Donations · last 30 days →</a>"
        );
    }

    protected function getData(): array
    {
        $days = collect(CarbonPeriod::create(now()->subDays(29), now()));

        // Captured-only — see DonationStatsOverview for the rationale.
        // Without this filter the daily bars include abandoned Razorpay
        // handshakes and the line graph misrepresents real receipts.
        $donations = Donation::selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'))
            ->where('created_at', '>=', now()->subDays(30))
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'date');

        return [
            'datasets' => [
                [
                    'label' => 'Daily Donations (₹)',
                    'data' => $days->map(fn ($day) => (float) ($donations[$day->format('Y-m-d')] ?? 0))->toArray(),
                    'borderColor' => '#F97316',
                    'backgroundColor' => 'rgba(249, 115, 22, 0.1)',
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
