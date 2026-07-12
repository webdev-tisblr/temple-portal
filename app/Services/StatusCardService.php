<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Devotee;
use App\Models\StatusTemplate;
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
    private const VERSION = 'v1';

    /** @return array{url: string, cached: bool}|null */
    public function generate(StatusTemplate $template, ?Devotee $devotee): ?array
    {
        if (! function_exists('imagecreatefrompng')) {
            Log::warning('StatusCardService: GD unavailable');

            return null;
        }

        $disk = Storage::disk('r2');
        $storagePath = $this->storagePathFor($template, $devotee);
        $cacheKey = 'status_card_url:' . $storagePath;

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
            $bytes = $this->render($template, $devotee);
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

    private function render(StatusTemplate $template, ?Devotee $devotee): ?string
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

        $fontPath = $this->resolveFontPath();
        $overlays = ($template->greeting_card_config['overlays'] ?? []);

        foreach ($overlays as $overlay) {
            $type = $overlay['type'] ?? 'text';
            $fieldKey = $overlay['field_key'] ?? null;
            if (! $fieldKey) {
                continue;
            }

            if ($type === 'image') {
                $photoPath = $devotee?->profile_photo_path;
                if ($photoPath) {
                    $this->applyImageOverlay($image, $overlay, $photoPath);
                }
                continue;
            }

            $value = match ($fieldKey) {
                '_donor_name' => $devotee?->name,
                '_date' => now()->setTimezone('Asia/Kolkata')->format('d M Y'),
                '_temple_name' => \App\Models\SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust'),
                default => null,
            };
            if ($value === null || $value === '') {
                continue;
            }

            $this->applyTextOverlay($image, $overlay, (string) $value, $fontPath);
        }

        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /** Deterministic path — same inputs regenerate the same object. */
    private function storagePathFor(StatusTemplate $template, ?Devotee $devotee): string
    {
        $seed = implode('|', [
            $template->id,
            optional($template->updated_at)->timestamp,
            $devotee?->getKey() ?? 'guest',
            $devotee?->name ?? '',
            $devotee?->profile_photo_path ?? '',
            self::VERSION,
        ]);

        return 'status-cards/' . sha1($seed) . '.png';
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
            $colorHex = $colorHex[0] . $colorHex[0] . $colorHex[1] . $colorHex[1] . $colorHex[2] . $colorHex[2];
        }
        $r = $g = $b = 0;
        sscanf($colorHex, '%02x%02x%02x', $r, $g, $b);
        $color = imagecolorallocate($image, (int) $r, (int) $g, (int) $b);

        if ($fontPath) {
            imagettftext($image, $fontSize, (float) ($overlay['angle'] ?? 0), $x, $y + (int) round($fontSize * 1.2), $color, $fontPath, $text);
        } else {
            imagestring($image, min(5, max(1, (int) round($fontSize / 4))), $x, $y, $text, $color);
        }
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

        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $w = (int) ($overlay['width'] ?? imagesx($photo));
        $h = (int) ($overlay['height'] ?? imagesy($photo));

        imagecopyresampled($image, $photo, $x, $y, 0, 0, $w, $h, imagesx($photo), imagesy($photo));
        imagedestroy($photo);
    }
}
