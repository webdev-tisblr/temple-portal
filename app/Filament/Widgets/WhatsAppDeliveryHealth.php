<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\NotificationLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * How many WhatsApp messages actually REACHED a devotee.
 *
 * Dispatch status ("sent") only means the BSP accepted the message. The
 * real outcome arrives later on the delivery webhook, and nothing surfaced
 * it — so a 28% failure rate on greeting cards ran for a week before
 * anyone noticed, and only because a devotee complained (2026-08-21).
 *
 * The two failure families need different people to act, so they are
 * separated rather than totalled:
 *
 *  • OUR FAULT — (#131008) missing parameter, (#131009) invalid parameter.
 *    A template placeholder resolved to nothing. Fixable in code/admin;
 *    `php artisan notifications:audit-placeholders` is the triage tool.
 *
 *  • META'S POLICY — "not delivered to maintain healthy ecosystem
 *    engagement". Only ever hits templates categorised as MARKETING; a
 *    UTILITY template is exempt. No code change helps, the template has
 *    to be recategorised with the BSP. This is what stops greeting cards
 *    while the donation confirmation beside them arrives fine.
 */
class WhatsAppDeliveryHealth extends StatsOverviewWidget
{
    protected static ?int $sort = 5;

    private const WINDOW_DAYS = 7;

    protected function getHeading(): ?string
    {
        return 'WhatsApp delivery (last '.self::WINDOW_DAYS.' days)';
    }

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_WhatsAppDeliveryHealth') ?? false;
    }

    protected function getStats(): array
    {
        $rows = NotificationLog::query()
            ->where('channel', 'whatsapp')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereNotNull('delivery_status')
            ->selectRaw('delivery_status, failure_reason, COUNT(*) as c')
            ->groupBy('delivery_status', 'failure_reason')
            ->get();

        $total = (int) $rows->sum('c');

        if ($total === 0) {
            return [
                Stat::make('Delivered', '—')
                    ->description('No delivery reports in the window')
                    ->color('gray'),
            ];
        }

        $ok = (int) $rows->whereIn('delivery_status', ['delivered', 'read', 'sent'])->sum('c');
        $failed = (int) $rows->where('delivery_status', 'failed')->sum('c');

        $reasonLike = fn (array $needles): int => (int) $rows
            ->where('delivery_status', 'failed')
            ->filter(function ($r) use ($needles) {
                foreach ($needles as $n) {
                    if (str_contains((string) $r->failure_reason, $n)) {
                        return true;
                    }
                }

                return false;
            })->sum('c');

        $ourFault = $reasonLike(['131008', '131009', '132000']);
        $metaPolicy = $reasonLike(['ecosystem', 'experiment']);

        $pct = (int) round($ok / $total * 100);

        // Which template is hurting most — the number alone does not tell
        // anyone where to look.
        $worst = NotificationLog::query()
            ->where('channel', 'whatsapp')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->where('delivery_status', 'failed')
            ->selectRaw('template_key, COUNT(*) as c')
            ->groupBy('template_key')
            ->orderByDesc('c')
            ->first();

        return [
            Stat::make('Reached the devotee', $pct.'%')
                ->description($ok.' of '.$total.' with a delivery report')
                ->color(match (true) {
                    $pct >= 95 => 'success',
                    $pct >= 85 => 'warning',
                    default => 'danger',
                }),

            Stat::make('Template/parameter errors', (string) $ourFault)
                ->description($ourFault > 0
                    ? 'Fixable here — run notifications:audit-placeholders'
                    : 'None — every placeholder resolved')
                ->color($ourFault > 0 ? 'danger' : 'success'),

            Stat::make('Blocked by Meta policy', (string) $metaPolicy)
                ->description($metaPolicy > 0
                    ? 'Ask the BSP to recategorise the template as UTILITY'
                    : 'None')
                ->color($metaPolicy > 0 ? 'warning' : 'success'),

            Stat::make('Worst template', $worst?->template_key ?? '—')
                ->description($worst ? $worst->c.' failed messages' : 'Nothing failing')
                ->color($failed > 0 ? 'warning' : 'gray'),
        ];
    }
}
