<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SevaBooking;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SevaBookingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getStats(): array
    {
        return [
            Stat::make("Today's Bookings", SevaBooking::whereDate('created_at', today())->count())
                ->color('success'),
            Stat::make("This Week's Bookings", SevaBooking::where('created_at', '>=', now()->startOfWeek())->count())
                ->color('primary'),
            Stat::make('Pending Bookings', SevaBooking::where('status', 'pending')->count())
                ->description('Awaiting payment/confirmation')
                ->color('warning'),
        ];
    }
}
