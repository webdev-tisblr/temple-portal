<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Devotee;
use App\Models\Donation;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class DonationStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_DonationStatsOverview') ?? false;
    }

    protected function getStats(): array
    {
        $fy = now()->month >= 4
            ? now()->year . '-' . substr((string) (now()->year + 1), -2)
            : (now()->year - 1) . '-' . substr((string) now()->year, -2);

        // Captured-only is mandatory — these tiles drive the trust's
        // "what did we actually receive" mental model. Without the
        // payment.status='captured' filter, abandoned Razorpay handshakes
        // (Payment row created but devotee never completed checkout)
        // inflate every total here. Matches the same guard applied in
        // Filament\Pages\FinancialReports::baseQuery().
        $captured = fn (): Builder => Donation::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'));

        return [
            Stat::make("Today's Donations", '₹' . number_format((float) $captured()->whereDate('created_at', today())->sum('amount')))
                ->description($captured()->whereDate('created_at', today())->count() . ' donations')
                ->color('success'),
            Stat::make('This Month', '₹' . number_format((float) $captured()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('amount')))
                ->description($captured()->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count() . ' donations')
                ->color('primary'),
            Stat::make("FY {$fy}", '₹' . number_format((float) $captured()->where('financial_year', $fy)->sum('amount')))
                ->description($captured()->where('financial_year', $fy)->count() . ' donations')
                ->color('warning'),
            Stat::make('Total Devotees', number_format(Devotee::count()))
                ->description('Registered users')
                ->color('info'),
        ];
    }
}
