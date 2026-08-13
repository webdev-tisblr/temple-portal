<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\ComingSoonMode;
use App\Models\SystemSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Turns coming-soon mode off once the configured launch moment passes.
 *
 * The middleware ALREADY opens the site at that moment on its own — this
 * command is not what makes the doors open, and must never be mistaken for
 * it. What it does is make the stored state match reality: flip the flag so
 * the admin dashboard stops claiming the site is locked, and purge
 * Cloudflare so the edge stops serving its cached copies of the
 * coming-soon 503.
 *
 * That purge is the part that bites. The edge Cache Rule covers `/`,
 * `darshan`, `gallery`, `projects`, `trustees` and `pages/*`, and because
 * the gate answers 503, Cloudflare's stale-on-error will keep serving those
 * cached copies indefinitely — the site would be open at origin and still
 * look shut to the world. This is the same mechanism as the 2026-07-28
 * outage. If no Cloudflare credentials are configured, the command says so
 * loudly rather than reporting success.
 */
class LaunchSite extends Command
{
    protected $signature = 'site:launch
                            {--force : Launch now, ignoring the configured time}';

    protected $description = 'Take the site out of coming-soon mode when the launch time arrives';

    public function handle(): int
    {
        $enabled = SystemSetting::getValue('coming_soon_mode') === '1';

        if (! $enabled) {
            return self::SUCCESS;
        }

        $launchAt = ComingSoonMode::launchAt();

        if (! $this->option('force')) {
            if ($launchAt === null) {
                return self::SUCCESS;
            }

            if ($launchAt->isFuture()) {
                $this->info("Launch at {$launchAt->format('d M Y, h:i A')} — {$launchAt->diffForHumans()}.");

                return self::SUCCESS;
            }
        }

        SystemSetting::updateOrCreate(
            ['key' => 'coming_soon_mode'],
            ['value' => '0', 'group' => 'system', 'updated_at' => now()],
        );

        Cache::forget('system.coming_soon_mode');
        Cache::forget('system.launch_at');

        Log::info('Site launched — coming-soon mode off', [
            'launch_at' => $launchAt?->toIso8601String(),
            'forced' => (bool) $this->option('force'),
        ]);

        $this->info('Coming-soon mode is OFF. The public site is live.');

        $this->purgeCloudflare();

        return self::SUCCESS;
    }

    /**
     * Purge the Cloudflare cache so the edge stops serving the 503 page.
     *
     * Deliberately noisy when unconfigured: a silent skip here looks like a
     * successful launch while every visitor still sees the coming-soon page.
     */
    private function purgeCloudflare(): void
    {
        $token = (string) config('services.cloudflare.api_token', '');
        $zone = (string) config('services.cloudflare.zone_id', '');

        if ($token === '' || $zone === '') {
            $this->warn('Cloudflare not configured (CLOUDFLARE_API_TOKEN / CLOUDFLARE_ZONE_ID).');
            $this->warn('PURGE THE CACHE BY HAND: dashboard → Caching → Configuration → Purge Everything,');
            $this->warn('and turn Always Online OFF, or the edge keeps serving the coming-soon page.');
            Log::warning('Site launched but Cloudflare purge skipped — no credentials configured');

            return;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->post("https://api.cloudflare.com/client/v4/zones/{$zone}/purge_cache", [
                    'purge_everything' => true,
                ]);

            if ($response->successful()) {
                $this->info('Cloudflare cache purged.');
                Log::info('Cloudflare cache purged after launch');

                return;
            }

            $this->error('Cloudflare purge FAILED: '.$response->body());
            Log::error('Cloudflare purge failed after launch', ['body' => $response->body()]);
        } catch (\Throwable $e) {
            $this->error('Cloudflare purge FAILED: '.$e->getMessage());
            Log::error('Cloudflare purge threw after launch', ['error' => $e->getMessage()]);
        }

        $this->warn('Purge the cache by hand from the Cloudflare dashboard.');
    }
}
