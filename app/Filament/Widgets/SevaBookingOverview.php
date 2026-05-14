<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SevaBooking;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

class SevaBookingOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_SevaBookingOverview') ?? false;
    }

    protected function getStats(): array
    {
        // Captured-only. "Today's Bookings" used to count every
        // SevaBooking row created today including ones where the devotee
        // closed Razorpay's modal mid-flow — those rows are abandoned
        // carts, not bookings the temple needs to prepare for. Same
        // logic as the user-facing dashboard fix.
        $captured = fn (): Builder => SevaBooking::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'));

        return [
            Stat::make("Today's Bookings", $captured()->whereDate('created_at', today())->count())
                ->color('success'),
            Stat::make("This Week's Bookings", $captured()->where('created_at', '>=', now()->startOfWeek())->count())
                ->color('primary'),
            // "Pending" now means CONFIRMED-but-upcoming (date >= today,
            // payment captured) — the actual operational queue the
            // priests need to see. The old semantic ("status=pending")
            // meant the abandoned-cart state, which is noise.
            Stat::make('Upcoming Bookings', $captured()->whereDate('booking_date', '>=', today())->count())
                ->description('Confirmed, date today or later')
                ->color('warning'),
        ];
    }
}
