<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyDarshanPhoto;
use App\Models\DarshanCardTemplate;
use App\Models\Devotee;
use App\Models\SystemSetting;
use App\Support\CardOverlayPainter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the daily-darshan share card from an ADMIN-DESIGNED template
 * (DarshanCardTemplate: uploaded background per language + drag-drop
 * overlay layout) instead of the programmatically-drawn design in
 * DarshanShareCardService — which remains the fallback when no template
 * is configured for the requested format.
 *
 * GD pipeline mirrors StatusCardService; output caching mirrors
 * DarshanShareCardService (deterministic public R2 path under
 * daily-darshan-cards/ so the existing cleanup cron sweeps these too,
 * and the endpoint regenerates on demand).
 *
 * Overlay field resolution:
 *   darshan_photo → the day's darshan photo (image slot, cover-cropped)
 *   user_photo    → devotee profile photo (image slot)
 *   _donor_name   → devotee name
 *   _caption      → photo caption in the render locale (gu fallback)
 *   _date         → today (d/m/Y, IST)
 *   _temple_name  → localized trust display name
 */
class DarshanCardTemplateService
{
    /**
     * @return array{url: string, format: string, width: int, height: int, cached: bool}|null
     */
    public function generate(DarshanCardTemplate $template, DailyDarshanPhoto $photo, ?Devotee $devotee, string $locale): ?array
    {
        if (! function_exists('imagecreatefrompng')) {
            Log::warning('DarshanCardTemplateService: GD unavailable');

            return null;
        }

        $locale = in_array($locale, ['gu', 'hi', 'en'], true) ? $locale : 'gu';

        $disk = Storage::disk('r2');
        $storagePath = $this->storagePathFor($template, $photo, $devotee, $locale);
        $cacheKey = 'darshan_card_url:'.$storagePath;

        // Deterministic path + memoised URL: repeat requests cost nothing.
        // Dimensions ride along in the cache entry so a hit never has to
        // re-measure the image.
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached['url'])) {
            return [...$cached, 'format' => $template->format, 'cached' => true];
        }

        try {
            $rendered = $this->render($template, $photo, $devotee, $locale);
        } catch (\Throwable $e) {
            Log::error('DarshanCardTemplateService: render failed', [
                'template_id' => $template->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if ($rendered === null) {
            return null;
        }

        [$bytes, $width, $height] = $rendered;

        $disk->put($storagePath, $bytes, [
            'visibility' => 'public',
            'ContentType' => 'image/jpeg',
            'CacheControl' => 'public, max-age=2592000',
        ]);

        $payload = ['url' => $disk->url($storagePath), 'width' => $width, 'height' => $height];
        Cache::put($cacheKey, $payload, now()->addHours(12));

        return [...$payload, 'format' => $template->format, 'cached' => false];
    }

    /**
     * @return array{0: string, 1: int, 2: int}|null [jpegBytes, width, height]
     */
    private function render(DarshanCardTemplate $template, DailyDarshanPhoto $photo, ?Devotee $devotee, string $locale): ?array
    {
        $bgPath = $this->templateForLocale($template, $locale);
        $bg = $bgPath ? Storage::disk('r2')->get($bgPath) : null;
        if (! $bg) {
            Log::warning('DarshanCardTemplateService: background image missing', [
                'template_id' => $template->id,
                'path' => $bgPath,
            ]);

            return null;
        }

        $image = imagecreatefromstring($bg);
        if (! $image) {
            return null;
        }

        // One resolver, used by both the single-variable overlays and the
        // rich text blocks, so the two can never disagree about what a
        // variable means on this card.
        $resolveText = fn (string $key): ?string => match ($key) {
            '_donor_name' => $devotee?->name,
            '_caption' => $this->captionForLocale($photo, $locale),
            // The photo's darshan date, not now() — the latest photo may be
            // from a previous day if an upload was missed.
            '_date' => ($photo->captured_on ?? now()->setTimezone('Asia/Kolkata'))->format('d/m/Y'),
            '_temple_name' => SystemSetting::getLocalized('trust_name', $locale, 'Shree Patadiya Hanumanji Seva Trust'),
            default => null,
        };

        $resolveImage = function (string $key) use ($photo, $devotee): ?string {
            $path = match ($key) {
                'darshan_photo' => $photo->image_path,
                'user_photo' => $devotee?->profile_photo_path,
                default => null,
            };
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

        $width = imagesx($image);
        $height = imagesy($image);

        ob_start();
        imagejpeg($image, null, 88);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return [$bytes, $width, $height];
    }

    /**
     * Background for a locale: bare column = Gujarati/default; hi/en
     * variants fall back to it. One overlay layout for all languages,
     * so variants must share the Gujarati image's dimensions.
     */
    private function templateForLocale(DarshanCardTemplate $template, string $locale): ?string
    {
        $path = match ($locale) {
            'hi' => $template->greeting_card_template_hi,
            'en' => $template->greeting_card_template_en,
            default => null,
        };

        return $path ?: $template->greeting_card_template;
    }

    private function captionForLocale(DailyDarshanPhoto $photo, string $locale): ?string
    {
        return match ($locale) {
            'hi' => $photo->caption_hi ?: $photo->caption_gu,
            'en' => $photo->caption_en ?: $photo->caption_gu,
            default => $photo->caption_gu,
        };
    }

    /** Deterministic path — same inputs regenerate the same object. */
    private function storagePathFor(DarshanCardTemplate $template, DailyDarshanPhoto $photo, ?Devotee $devotee, string $locale): string
    {
        $date = optional($photo->captured_on)->toDateString() ?: now()->toDateString();

        // 'tpl-v3': shared CardOverlayPainter — shaped overlays lost pango's
        // 10px margin and rich blocks became per-language, so cached renders
        // must not be reused. 'tpl-v2': footer date = photo captured_on.
        $seed = implode('|', [
            'tpl-v3',
            $template->id,
            optional($template->updated_at)->timestamp,
            $photo->id,
            optional($photo->updated_at)->timestamp,
            $devotee?->getKey() ?? 'guest',
            $devotee?->name ?? '',
            $devotee?->profile_photo_path ?? '',
            $template->format,
            $locale,
        ]);

        // Shares the daily-darshan-cards/ prefix so darshan:clean-share-cards
        // sweeps these objects too; the API regenerates on demand.
        $devoteeSegment = $devotee ? 'd'.substr(sha1((string) $devotee->getKey()), 0, 8) : 'guest';

        return "daily-darshan-cards/{$date}/tpl-{$devoteeSegment}-{$template->format}-{$locale}-".substr(sha1($seed), 0, 12).'.jpg';
    }
}
