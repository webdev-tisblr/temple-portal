<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\SystemSetting;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard widget — single toggle that flips the entire public
 * website into "coming soon" mode. The admin panel keeps working
 * because ComingSoonMode middleware bypasses /admin and /api.
 */
class ComingSoonToggleWidget extends Widget
{
    protected static string $view = 'filament.widgets.coming-soon-toggle';

    // Top of the dashboard so it's the first thing the admin sees.
    protected static ?int $sort = -1;

    // Full-width single row.
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth('admin')->user()?->can('widget_ComingSoonToggleWidget') ?? false;
    }

    public bool $enabled = false;

    public function mount(): void
    {
        $this->enabled = SystemSetting::getValue('coming_soon_mode') === '1';
    }

    /**
     * Livewire action fired by the Alpine-driven toggle in the
     * widget view. Persists the new state, invalidates the
     * middleware's 60-second cache, and surfaces a toast so the
     * admin sees the change is live.
     */
    public function toggle(): void
    {
        $this->enabled = ! $this->enabled;

        SystemSetting::updateOrCreate(
            ['key' => 'coming_soon_mode'],
            [
                'value' => $this->enabled ? '1' : '0',
                'group' => 'system',
                'updated_at' => now(),
            ],
        );

        // Make the new state visible to the public site immediately —
        // ComingSoonMode middleware caches the value for 60 seconds.
        Cache::forget('system.coming_soon_mode');

        Notification::make()
            ->title($this->enabled
                ? 'Coming-soon mode ON — public site is locked'
                : 'Coming-soon mode OFF — public site is live')
            ->color($this->enabled ? 'warning' : 'success')
            ->send();
    }
}
