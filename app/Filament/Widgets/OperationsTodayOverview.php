<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\ContactSubmissionResource;
use App\Filament\Resources\HallBookingResource;
use App\Filament\Resources\OrderResource;
use App\Filament\Resources\SevaBookingResource;
use App\Models\ContactSubmission;
use App\Models\HallBooking;
use App\Models\Order;
use App\Models\SevaBooking;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * "What's on the trust's plate today" row. The financial overview
 * above answers "how much did we receive" — this answers "what do we
 * actually need to prepare / respond to today".
 *
 * Every booking/order count is captured-only — abandoned Razorpay
 * checkouts (Payment row exists but never reached status=captured)
 * are noise the priests should never see on the dashboard. Same
 * guard the public dashboards + FinancialReports use.
 */
class OperationsTodayOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getHeading(): ?string
    {
        return 'Today';
    }

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_OperationsTodayOverview') ?? false;
    }

    protected function getStats(): array
    {
        $captured = fn (string $modelClass): Builder => $modelClass::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'));

        $todayServiceBookings = SevaBooking::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'))
            ->whereDate('booking_date', today())
            ->count();

        $todayHallBookings = HallBooking::query()
            ->whereHas('payment', fn (Builder $q) => $q->where('status', 'captured'))
            ->whereDate('booking_date', today())
            ->count();

        // Store orders use status enum, not date — show orders captured
        // today that still need fulfillment (pending or confirmed).
        $todayOrders = $captured(Order::class)
            ->whereDate('created_at', today())
            ->count();

        $unreadContacts = ContactSubmission::query()
            ->where('is_read', false)
            ->count();

        return [
            Stat::make('Seva bookings today', $todayServiceBookings)
                ->description('Confirmed for today')
                ->descriptionColor('gray')
                ->color($todayServiceBookings > 0 ? 'warning' : 'gray')
                ->url(SevaBookingResource::getUrl()),

            Stat::make('Hall bookings today', $todayHallBookings)
                ->description('Confirmed for today')
                ->descriptionColor('gray')
                ->color($todayHallBookings > 0 ? 'warning' : 'gray')
                ->url(HallBookingResource::getUrl()),

            Stat::make('Store orders today', $todayOrders)
                ->description('Captured payments')
                ->descriptionColor('gray')
                ->color($todayOrders > 0 ? 'primary' : 'gray')
                ->url(OrderResource::getUrl()),

            Stat::make('Unread messages', $unreadContacts)
                ->description($unreadContacts > 0 ? 'Need a reply' : 'Inbox clear')
                ->descriptionColor($unreadContacts > 0 ? 'danger' : 'success')
                ->color($unreadContacts > 0 ? 'danger' : 'success')
                ->url(ContactSubmissionResource::getUrl()),
        ];
    }
}
