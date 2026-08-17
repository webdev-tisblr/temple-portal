<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\DeviceToken;
use App\Models\SystemSetting;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * How far a release has actually reached devotees' phones.
 *
 * The data was already being collected — every device token carries the
 * `app_version` and `platform` the app reported when it registered — but
 * nothing surfaced it, so "how many people are on the new build?" had no
 * answer short of a SQL console (2026-08-17).
 *
 * Counted over ACTIVE tokens seen in the last 30 days, not every row ever
 * written: a token from an uninstalled app or a replaced handset would
 * otherwise sit in the denominator forever and make adoption look permanently
 * stuck. That also means the numbers here are "reachable installs", the same
 * population a push notification would land on.
 *
 * `app_latest_version` (System Settings) is what "up to date" is measured
 * against — the same value the app's own update check reads. If nobody
 * updates that setting when a release goes live, this widget will show 0%
 * adoption of a version it does not know exists.
 */
class AppVersionAdoption extends StatsOverviewWidget
{
    protected static ?int $sort = 4;

    /** Tokens quieter than this are treated as gone, not as stragglers. */
    private const ACTIVE_WINDOW_DAYS = 30;

    protected function getHeading(): ?string
    {
        return 'App version adoption';
    }

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_AppVersionAdoption') ?? false;
    }

    protected function getStats(): array
    {
        $latest = trim((string) SystemSetting::getValue('app_latest_version', ''));

        $rows = DeviceToken::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('last_used_at', '>=', now()->subDays(self::ACTIVE_WINDOW_DAYS))
                ->orWhereNull('last_used_at'))
            ->selectRaw('platform, app_version, COUNT(*) as installs')
            ->groupBy('platform', 'app_version')
            ->get();

        $total = (int) $rows->sum('installs');

        if ($total === 0) {
            return [
                Stat::make('Reachable installs', '0')
                    ->description('No active device tokens in the last '.self::ACTIVE_WINDOW_DAYS.' days')
                    ->color('gray'),
            ];
        }

        $onLatest = $latest === ''
            ? 0
            : (int) $rows->where('app_version', $latest)->sum('installs');

        $pct = (int) round($onLatest / $total * 100);

        // A version the app never reported lands as NULL — an older build
        // that predates version reporting. Called out rather than folded into
        // "not updated", because it is a different thing and cannot be chased.
        $unknown = (int) $rows->whereNull('app_version')->sum('installs');

        $byPlatform = fn (string $platform): string => (function () use ($rows, $platform, $latest): string {
            $installs = $rows->where('platform', $platform);
            $sum = (int) $installs->sum('installs');
            if ($sum === 0) {
                return 'none';
            }
            $up = $latest === '' ? 0 : (int) $installs->where('app_version', $latest)->sum('installs');

            return $up.' / '.$sum.' ('.(int) round($up / $sum * 100).'%)';
        })();

        return [
            Stat::make('On the latest build', $latest === '' ? '—' : $pct.'%')
                ->description($latest === ''
                    ? 'Set app_latest_version in System Settings to measure this'
                    : $onLatest.' of '.$total.' installs on '.$latest)
                ->color(match (true) {
                    $latest === '' => 'gray',
                    $pct >= 80 => 'success',
                    $pct >= 40 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Android', $byPlatform('android'))
                ->description('Updated / reachable')
                ->color('gray'),

            Stat::make('iOS', $byPlatform('ios'))
                ->description('Updated / reachable')
                ->color('gray'),

            Stat::make('Versions in the wild', (string) $rows->pluck('app_version')->filter()->unique()->count())
                ->description($unknown > 0
                    ? $unknown.' install(s) too old to report a version'
                    : 'Every install reports its version')
                ->color($unknown > 0 ? 'warning' : 'gray'),
        ];
    }
}
