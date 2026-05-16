<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Resources\DonationCampaignResource;
use App\Filament\Resources\EventResource;
use App\Filament\Resources\HallResource;
use App\Filament\Resources\SevaResource;
use App\Models\DonationCampaign;
use App\Models\Event;
use App\Models\Hall;
use App\Models\Seva;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * "What's currently published" row. Surfaces the live catalogue at a
 * glance so the trust knows how many sevas / events / campaigns /
 * halls are actually reachable from the mobile and web. Helps spot
 * "we forgot to activate the new campaign" moments without having to
 * dig into each resource.
 */
class ActiveContentOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    protected function getHeading(): ?string
    {
        return 'Active content';
    }

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_ActiveContentOverview') ?? false;
    }

    protected function getStats(): array
    {
        $activeCampaigns = DonationCampaign::query()->where('is_active', true);
        $activeCampaignCount = (clone $activeCampaigns)->count();
        $totalRaised = (float) (clone $activeCampaigns)->sum('raised_amount');
        $totalGoal = (float) (clone $activeCampaigns)->sum('goal_amount');
        $raisedPct = $totalGoal > 0 ? (int) round($totalRaised / $totalGoal * 100) : 0;

        $activeSevas = Seva::query()->where('is_active', true)->count();

        $upcomingEvents = Event::query()
            ->where('start_date', '>=', today())
            ->count();

        $activeHalls = Hall::query()->where('is_active', true)->count();

        return [
            Stat::make('Active campaigns', number_format($activeCampaignCount))
                ->description($activeCampaignCount > 0
                    ? '₹' . number_format($totalRaised) . ' raised · ' . $raisedPct . '%'
                    : 'None published')
                ->descriptionColor('gray')
                ->color('success')
                ->url(DonationCampaignResource::getUrl()),

            Stat::make('Active sevas', number_format($activeSevas))
                ->description('Bookable now')
                ->descriptionColor('gray')
                ->color('primary')
                ->url(SevaResource::getUrl()),

            Stat::make('Upcoming events', number_format($upcomingEvents))
                ->description('Today or later')
                ->descriptionColor('gray')
                ->color('warning')
                ->url(EventResource::getUrl()),

            Stat::make('Active halls', number_format($activeHalls))
                ->description('Bookable now')
                ->descriptionColor('gray')
                ->color('info')
                ->url(HallResource::getUrl()),
        ];
    }
}
