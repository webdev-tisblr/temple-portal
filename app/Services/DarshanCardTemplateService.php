<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyDarshanPhoto;
use App\Models\DarshanCardTemplate;
use App\Models\Devotee;
use App\Models\SystemSetting;
use App\Support\ScriptFont;
use App\Support\ShapedText;
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

        $fontPath = $this->resolveFontPath();

        foreach (($template->greeting_card_config['overlays'] ?? []) as $overlay) {
            $type = $overlay['type'] ?? 'text';
            $fieldKey = $overlay['field_key'] ?? null;
            if (! $fieldKey) {
                continue;
            }

            if ($type === 'image') {
                $path = match ($fieldKey) {
                    'darshan_photo' => $photo->image_path,
                    'user_photo' => $devotee?->profile_photo_path,
                    default => null,
                };
                if ($path) {
                    $this->applyImageOverlay($image, $overlay, $path);
                }

                continue;
            }

            $value = match ($fieldKey) {
                '_donor_name' => $devotee?->name,
                '_caption' => $this->captionForLocale($photo, $locale),
                // The photo's darshan date, not now() — the latest photo
                // may be from a previous day if an upload was missed.
                '_date' => ($photo->captured_on ?? now()->setTimezone('Asia/Kolkata'))->format('d/m/Y'),
                '_temple_name' => SystemSetting::getLocalized('trust_name', $locale, 'Shree Patadiya Hanumanji Seva Trust'),
                default => null,
            };
            if ($value === null || $value === '') {
                continue;
            }

            $this->applyTextOverlay(
                $image,
                $overlay,
                (string) $value,
                ScriptFont::forText((string) $value) ?? $fontPath,
            );
        }

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

        // 'tpl-v2': footer date = photo captured_on (was now()) — bumped so
        // cards cached with a wrong date regenerate.
        $seed = implode('|', [
            'tpl-v2',
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

    private function resolveFontPath(): ?string
    {
        foreach ([
            resource_path('fonts/DejaVuSans.ttf'),
            base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf'),
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        ] as $font) {
            if (file_exists($font)) {
                return $font;
            }
        }

        return null;
    }

    private function applyTextOverlay(\GdImage $image, array $overlay, string $text, ?string $fontPath): void
    {
        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $fontSize = (float) ($overlay['font_size'] ?? 16);
        $colorHex = ltrim($overlay['color'] ?? '#000000', '#');
        if (strlen($colorHex) === 3) {
            $colorHex = $colorHex[0].$colorHex[0].$colorHex[1].$colorHex[1].$colorHex[2].$colorHex[2];
        }
        $r = $g = $b = 0;
        sscanf($colorHex, '%02x%02x%02x', $r, $g, $b);
        $color = imagecolorallocate($image, (int) $r, (int) $g, (int) $b);

        $width = (int) ($overlay['width'] ?? 0);
        $angleForShaping = (float) ($overlay['angle'] ?? 0);

        // Indic text (Gujarati/Devanagari): GD cannot shape it — render via
        // pango (ShapedText) and composite; the GD path below stays as the
        // fallback for Latin, rotated overlays, and hosts without pango.
        if ($angleForShaping === 0.0
            && ShapedText::needsShaping($text)
            && ShapedText::available()
        ) {
            $png = ShapedText::render($text, $fontSize, $colorHex, $width > 0 ? $width : null);
            if ($png instanceof \GdImage) {
                $dx = $width > 0 ? $x + (int) round(max(0, ($width - imagesx($png)) / 2)) : $x;
                imagealphablending($image, true);
                imagecopy($image, $png, $dx, $y, 0, 0, imagesx($png), imagesy($png));
                imagedestroy($png);

                return;
            }
        }

        if ($fontPath && $width > 0) {
            $lines = $this->wrapText($text, $fontSize, $fontPath, $width);
            $lineHeight = $fontSize * 1.4;
            $ly = $y + $fontSize; // first baseline
            foreach ($lines as $line) {
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $line);
                $lineW = abs($bbox[2] - $bbox[0]);
                $lx = $x + (int) round(($width - $lineW) / 2);
                imagettftext($image, $fontSize, 0, $lx, (int) round($ly), $color, $fontPath, $line);
                $ly += $lineHeight;
            }
        } elseif ($fontPath) {
            imagettftext($image, $fontSize, (float) ($overlay['angle'] ?? 0), $x, $y + (int) round($fontSize * 1.2), $color, $fontPath, $text);
        } else {
            imagestring($image, min(5, max(1, (int) round($fontSize / 4))), $x, $y, $text, $color);
        }
    }

    /**
     * Greedy word-wrap: split $text into lines that each fit within
     * $maxWidth px at the given font size.
     *
     * @return list<string>
     */
    private function wrapText(string $text, float $fontSize, string $fontPath, int $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $trial = $current === '' ? $word : $current.' '.$word;
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $trial);
            $trialWidth = abs($bbox[2] - $bbox[0]);
            if ($trialWidth > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $trial;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines ?: [$text];
    }

    private function applyImageOverlay(\GdImage $image, array $overlay, string $storagePath): void
    {
        try {
            $bytes = Storage::disk('r2')->get($storagePath);
        } catch (\Throwable) {
            return;
        }
        if (! $bytes) {
            return;
        }

        $photo = imagecreatefromstring($bytes);
        if (! $photo) {
            return;
        }

        $photo = $this->applyExifOrientation($photo, $bytes);

        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $srcW = imagesx($photo);
        $srcH = imagesy($photo);
        $w = (int) ($overlay['width'] ?? $srcW);
        $h = (int) ($overlay['height'] ?? $srcH);

        if (($overlay['shape'] ?? 'square') === 'circle') {
            $this->coverIntoCircle($image, $photo, $x, $y, $w, $h, $srcW, $srcH);
        } else {
            $this->coverInto($image, $photo, $x, $y, $w, $h, $srcW, $srcH);
        }
        imagedestroy($photo);
    }

    /**
     * "Cover" composite: fill the box, preserve aspect, center-crop overflow.
     */
    private function coverInto(\GdImage $dst, \GdImage $src, int $x, int $y, int $w, int $h, int $srcW, int $srcH): void
    {
        if ($w <= 0 || $h <= 0 || $srcW <= 0 || $srcH <= 0) {
            return;
        }

        $targetRatio = $w / $h;
        $srcRatio = $srcW / $srcH;

        if ($srcRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $srcX = (int) round(($srcW - $cropW) / 2);
            $srcY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $srcX = 0;
            $srcY = (int) round(($srcH - $cropH) / 2);
        }

        imagecopyresampled($dst, $src, $x, $y, $srcX, $srcY, $w, $h, max(1, $cropW), max(1, $cropH));
    }

    /**
     * coverInto() clipped to an inscribed ellipse with a feathered edge.
     */
    private function coverIntoCircle(\GdImage $dst, \GdImage $src, int $x, int $y, int $w, int $h, int $srcW, int $srcH): void
    {
        if ($w <= 0 || $h <= 0 || $srcW <= 0 || $srcH <= 0) {
            return;
        }

        $temp = imagecreatetruecolor($w, $h);
        imagealphablending($temp, false);
        imagesavealpha($temp, true);
        $transparent = imagecolorallocatealpha($temp, 0, 0, 0, 127);
        imagefilledrectangle($temp, 0, 0, $w, $h, $transparent);

        $targetRatio = $w / $h;
        $srcRatio = $srcW / $srcH;
        if ($srcRatio > $targetRatio) {
            $cropH = $srcH;
            $cropW = (int) round($srcH * $targetRatio);
            $srcX = (int) round(($srcW - $cropW) / 2);
            $srcY = 0;
        } else {
            $cropW = $srcW;
            $cropH = (int) round($srcW / $targetRatio);
            $srcX = 0;
            $srcY = (int) round(($srcH - $cropH) / 2);
        }
        imagecopyresampled($temp, $src, 0, 0, $srcX, $srcY, $w, $h, max(1, $cropW), max(1, $cropH));

        $rx = $w / 2.0;
        $ry = $h / 2.0;
        $feather = 1.5 / min($rx, $ry);
        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $nx = ($px + 0.5 - $rx) / $rx;
                $ny = ($py + 0.5 - $ry) / $ry;
                $d = sqrt($nx * $nx + $ny * $ny);
                $coverage = max(0.0, min(1.0, (1.0 - $d) / $feather + 0.5));
                if ($coverage >= 1.0) {
                    continue;
                }
                $rgba = imagecolorat($temp, $px, $py);
                $alpha = (int) round((1.0 - $coverage) * 127);
                imagesetpixel($temp, $px, $py, ($alpha << 24) | ($rgba & 0xFFFFFF));
            }
        }

        imagealphablending($dst, true);
        imagecopy($dst, $temp, $x, $y, 0, 0, $w, $h);
        imagedestroy($temp);
    }

    /**
     * Re-orient a GD image per its source EXIF Orientation tag.
     */
    private function applyExifOrientation(\GdImage $photo, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $photo;
        }

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        } catch (\Throwable) {
            return $photo;
        }

        $orientation = (int) ($exif['Orientation'] ?? 0);
        if ($orientation <= 1) {
            return $photo;
        }

        if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
            imageflip($photo, IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => -90,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $rotated = imagerotate($photo, $angle, 0);
            if ($rotated instanceof \GdImage) {
                imagedestroy($photo);

                return $rotated;
            }
        }

        return $photo;
    }
}
