<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\DevoteeResource;
use App\Models\DeviceToken;
use App\Models\Devotee;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Devotee reach + activity row. Distinct from operations (today's
 * to-do list) and finances (₹) — this is the "audience health"
 * snapshot the trust looks at when deciding whether to push a
 * notification, announce a new campaign, etc.
 *
 * `push-reachable` mirrors what SendPushNotification's resolveTokens
 * sees: active device_tokens (regardless of devotee link, since the
 * `all` segment includes anonymous installs too).
 */
class EngagementOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 3;

    protected function getHeading(): ?string
    {
        return 'Devotees';
    }

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_EngagementOverview') ?? false;
    }

    protected function getStats(): array
    {
        $total = Devotee::count();
        $active30d = Devotee::query()
            ->where('last_login_at', '>=', now()->subDays(30))
            ->count();
        $newThisMonth = Devotee::query()
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
        $pushReachable = DeviceToken::query()->where('is_active', true)->count();

        // Active rate descriptor — gives a sense of audience engagement
        // without adding a fifth tile. Guard against div-by-zero on a
        // fresh install with no devotees yet.
        $activePct = $total > 0 ? (int) round($active30d / $total * 100) : 0;

        // All four tiles land on the Devotees list — there's no
        // separate DeviceToken resource so push-reachable also goes
        // there (devotee → device tokens via the model's relation).
        $devoteesUrl = DevoteeResource::getUrl();

        return [
            Stat::make('Total devotees', number_format($total))
                ->description('Registered accounts')
                ->descriptionColor('gray')
                ->color('info')
                ->url($devoteesUrl),

            Stat::make('Active · 30 days', number_format($active30d))
                ->description("{$activePct}% of total")
                ->descriptionColor('gray')
                ->color('success')
                ->url($devoteesUrl),

            Stat::make('New this month', number_format($newThisMonth))
                ->description('Joined since the 1st')
                ->descriptionColor('gray')
                ->color('primary')
                ->url($devoteesUrl),

            Stat::make('Push-reachable', number_format($pushReachable))
                ->description('Devices accepting notifications')
                ->descriptionColor('gray')
                ->color('warning')
                ->url($devoteesUrl),
        ];
    }
}
