<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Devotee;
use App\Models\StatusTemplate;
use App\Models\SystemSetting;
use App\Support\CardOverlayPainter;
use App\Support\DevoteeLocale;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * On-demand personalised status/greeting images: takes an admin-designed
 * StatusTemplate (background + drag-drop overlay slots) and composites the
 * devotee's name/photo into it. GD pipeline mirrors GreetingCardService;
 * output caching mirrors DarshanShareCardService (deterministic public R2
 * path + memoised URL, so repeat requests cost nothing).
 *
 * Overlay field resolution:
 *   _donor_name  → devotee name (the editor's generic "name" slot)
 *   _date        → today (d M Y)
 *   _temple_name → trust display name
 *   user_photo   → devotee profile photo (image slot)
 */
class StatusCardService
{
    // Bump to invalidate cached cards when the compositing logic changes
    // (v2: EXIF-orientation correction + aspect-preserving "cover" photo fit;
    //  v3: script-aware fonts so Gujarati/Hindi names render, not tofu).
    // v7: shared CardOverlayPainter — shaped text no longer carries pango-view's
    //     10px margin, so overlays land where the editor shows them; rich text
    //     blocks are per-language. v6: Pataliya → Patadiya spelling; v5: LC_ALL
    //     fix — v4 FPM renders were still unshaped (C locale).
    private const VERSION = 'v7';

    /**
     * @param  string|null  $photoBytes  Raw bytes of a one-off photo the
     *                                   devotee uploaded for this card; when
     *                                   set it replaces the profile photo in
     *                                   the template's image slot.
     * @return array{url: string, cached: bool}|null
     */
    public function generate(StatusTemplate $template, ?Devotee $devotee, ?string $photoBytes = null): ?array
    {
        if (! function_exists('imagecreatefrompng')) {
            Log::warning('StatusCardService: GD unavailable');

            return null;
        }

        $disk = Storage::disk('r2');
        $storagePath = $this->storagePathFor($template, $devotee, $photoBytes);
        $cacheKey = 'status_card_url:'.$storagePath;

        $cachedUrl = Cache::get($cacheKey);
        if (is_string($cachedUrl) && $cachedUrl !== '') {
            return ['url' => $cachedUrl, 'cached' => true];
        }

        if ($disk->exists($storagePath)) {
            $url = $disk->url($storagePath);
            Cache::put($cacheKey, $url, now()->addHours(12));

            return ['url' => $url, 'cached' => true];
        }

        try {
            $bytes = $this->render($template, $devotee, $photoBytes);
        } catch (\Throwable $e) {
            Log::error('StatusCardService: render failed', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if ($bytes === null) {
            return null;
        }

        $disk->put($storagePath, $bytes, [
            'visibility' => 'public',
            'ContentType' => 'image/png',
            'CacheControl' => 'public, max-age=2592000',
        ]);

        $url = $disk->url($storagePath);
        Cache::put($cacheKey, $url, now()->addHours(12));

        return ['url' => $url, 'cached' => false];
    }

    private function render(StatusTemplate $template, ?Devotee $devotee, ?string $photoBytes = null): ?string
    {
        $bg = Storage::disk('r2')->get($template->greeting_card_template);
        if (! $bg) {
            Log::warning('StatusCardService: template image missing', ['template_id' => $template->id]);

            return null;
        }

        $image = imagecreatefromstring($bg);
        if (! $image) {
            return null;
        }

        // The request locale (X-Locale from the app, or the web session) —
        // it decides which language of a rich text block is drawn. Status
        // cards are made on demand by the devotee, so there is always one.
        $locale = in_array(app()->getLocale(), DevoteeLocale::SUPPORTED, true) ? app()->getLocale() : DevoteeLocale::FALLBACK;

        // One resolver for single-variable overlays and rich blocks alike.
        $resolveText = fn (string $key): ?string => match ($key) {
            '_donor_name' => $devotee?->name,
            '_date' => now()->setTimezone('Asia/Kolkata')->format('d M Y'),
            '_temple_name' => SystemSetting::getLocalized('trust_name', $locale, 'Shree Patadiya Hanumanji Seva Trust'),
            default => null,
        };

        // Any image slot shows the devotee's picture: the one-off upload sent
        // with this request wins over the profile photo; neither → empty slot.
        $resolveImage = function (string $key) use ($devotee, $photoBytes): ?string {
            if ($photoBytes !== null) {
                return $photoBytes;
            }

            $path = $devotee?->profile_photo_path;
            if (! $path) {
                return null;
            }

            try {
                return Storage::disk('r2')->get($path) ?: null;
            } catch (\Throwable) {
                return null;
            }
        };

        CardOverlayPainter::compose(
            $image,
            $template->greeting_card_config['overlays'] ?? [],
            $resolveText,
            $resolveImage,
            $locale,
        );

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** Deterministic path — same inputs regenerate the same object. */
    private function storagePathFor(StatusTemplate $template, ?Devotee $devotee, ?string $photoBytes = null): string
    {
        $seed = implode('|', [
            $template->id,
            optional($template->updated_at)->timestamp,
            $devotee?->getKey() ?? 'guest',
            $devotee?->name ?? '',
            // A one-off uploaded photo keys the object by its content hash,
            // so a re-upload of the same picture still hits the cache.
            $photoBytes !== null ? 'up:'.sha1($photoBytes) : ($devotee?->profile_photo_path ?? ''),
            // Rich text blocks are per-language (2026-09-12), so the same
            // devotee asking in Hindi must not be served the Gujarati render.
            app()->getLocale(),
            self::VERSION,
        ]);

        return 'status-cards/'.sha1($seed).'.png';
    }
}
