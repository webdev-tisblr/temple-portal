<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Donation;
use Carbon\CarbonPeriod;
use Filament\Widgets\ChartWidget;

class DonationChart extends ChartWidget
{
    protected static ?string $heading = 'Donations (Last 30 Days)';

    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $days = collect(CarbonPeriod::create(now()->subDays(29), now()));

        $donations = Donation::selectRaw('DATE(created_at) as date, SUM(amount) as total')
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
