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

        $width = (int) ($overlay['width'] ?? 0);

        if ($fontPath && $width > 0) {
            // Centre each line within the overlay's width box, wrapping long
            // text onto new lines.
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
     * Greedy word-wrap: split $text into lines that each fit within $maxWidth
     * px at the given font size. Very long single words are left intact.
     *
     * @return list<string>
     */
    private function wrapText(string $text, float $fontSize, string $fontPath, int $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $trial = $current === '' ? $word : $current . ' ' . $word;
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

        // Phone photos carry an EXIF orientation tag; GD ignores it, so a
        // portrait shot composites sideways (the "6 o'clock → 9 o'clock"
        // rotation). Re-orient the pixels before compositing.
        $photo = $this->applyExifOrientation($photo, $bytes);

        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $w = (int) ($overlay['width'] ?? imagesx($photo));
        $h = (int) ($overlay['height'] ?? imagesy($photo));

        imagecopyresampled($image, $photo, $x, $y, 0, 0, $w, $h, imagesx($photo), imagesy($photo));
        imagedestroy($photo);
    }

    /**
     * Re-orient a GD image per its source EXIF Orientation tag. GD's
     * imagecreatefromstring drops EXIF, so phone photos otherwise composite
     * rotated/mirrored. Handles all 8 orientations; returns the (possibly new)
     * GdImage and destroys the original when it rotates.
     */
    private function applyExifOrientation(\GdImage $photo, string $bytes): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $photo;
        }

        try {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        } catch (\Throwable) {
            return $photo;
        }

        $orientation = (int) ($exif['Orientation'] ?? 0);
        if ($orientation <= 1) {
            return $photo; // 0/1 = already upright, or no tag
        }

        // Mirror first for the flipped orientations, then rotate.
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
