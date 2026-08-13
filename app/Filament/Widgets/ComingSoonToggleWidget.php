<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Http\Middleware\ComingSoonMode;
use App\Models\SystemSetting;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Computed;

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

    /** Wall-clock IST, in the browser datetime-local shape (Y-m-d\TH:i). */
    public ?string $launchAt = null;

    public function mount(): void
    {
        $this->enabled = SystemSetting::getValue('coming_soon_mode') === '1';
        $this->launchAt = ComingSoonMode::launchAt()?->format('Y-m-d\\TH:i');
    }

    /**
     * ISO-8601 WITH the IST offset, for the summary line under the field.
     *
     * #[Computed], not the getXProperty magic — Livewire 3 dropped that, and
     * a silent null here would have hidden the confirmation line entirely.
     */
    #[Computed]
    public function launchIso(): ?string
    {
        return ComingSoonMode::launchAt()?->toIso8601String();
    }

    /**
     * Save the launch moment. Clearing the field removes it entirely, which
     * puts the site back under manual control.
     */
    public function saveLaunchAt(): void
    {
        $value = trim((string) $this->launchAt);

        if ($value === '') {
            SystemSetting::where('key', 'launch_at')->delete();
            Cache::forget('system.launch_at');

            Notification::make()
                ->title('Launch time cleared — the site stays hidden until you flip the switch')
                ->color('warning')
                ->send();

            return;
        }

        try {
            $when = Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable) {
            Notification::make()->title('That does not look like a valid date and time.')->danger()->send();

            return;
        }

        SystemSetting::updateOrCreate(
            ['key' => 'launch_at'],
            ['value' => $when->format('Y-m-d H:i:s'), 'group' => 'system', 'updated_at' => now()],
        );

        Cache::forget('system.launch_at');
        $this->launchAt = $when->format('Y-m-d\\TH:i');

        Notification::make()
            ->title('Launch set for '.$when->format('d M Y, h:i A'))
            ->body($when->isPast()
                ? 'That time has already passed — the site is open now.'
                : 'The countdown is live on the coming-soon page. The site opens by itself.')
            ->color($when->isPast() ? 'warning' : 'success')
            ->send();
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
        Cache::forget('system.launch_at');

        Notification::make()
            ->title($this->enabled
                ? 'Coming-soon mode ON — public site is locked'
                : 'Coming-soon mode OFF — public site is live')
            ->color($this->enabled ? 'warning' : 'success')
            ->send();
    }
}
