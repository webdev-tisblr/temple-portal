<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Devotee;
use App\Models\Donation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DonationStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $fy = now()->month >= 4
            ? now()->year . '-' . substr((string) (now()->year + 1), -2)
            : (now()->year - 1) . '-' . substr((string) now()->year, -2);

        return [
            Stat::make("Today's Donations", '₹' . number_format((float) Donation::whereDate('created_at', today())->sum('amount')))
                ->description(Donation::whereDate('created_at', today())->count() . ' donations')
                ->color('success'),
            Stat::make('This Month', '₹' . number_format((float) Donation::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('amount')))
                ->description(Donation::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count() . ' donations')
                ->color('primary'),
            Stat::make("FY {$fy}", '₹' . number_format((float) Donation::where('financial_year', $fy)->sum('amount')))
                ->description(Donation::where('financial_year', $fy)->count() . ' donations')
                ->color('warning'),
            Stat::make('Total Devotees', number_format(Devotee::count()))
                ->description('Registered users')
                ->color('info'),
        ];
    }
}
