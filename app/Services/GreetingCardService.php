<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Donation;
use App\Support\ScriptFont;
use App\Support\ShapedText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GreetingCardService
{
    /**
     * Generate a greeting card image for a donation.
     * Returns the storage path or null if no config/template.
     */
    public function generate(Donation $donation): ?string
    {
        if (! function_exists('imagecreatefrompng')) {
            Log::warning('GD extension not available, skipping greeting card generation');

            return null;
        }

        try {
            return $this->generateCard($donation);
        } catch (\Throwable $e) {
            Log::error('Greeting card generation failed', [
                'donation_id' => $donation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function generateCard(Donation $donation): ?string
    {
        $donation->loadMissing('donationType', 'devotee');
        $donationType = $donation->donationType;

        if (! $donationType || ! $donationType->greeting_card_template || ! $donationType->greeting_card_config) {
            return null;
        }

        $overlays = $donationType->greeting_card_config['overlays'] ?? [];
        if (empty($overlays)) {
            return null;
        }

        // Load the template image bytes from R2 public bucket.
        try {
            $templateBytes = Storage::disk('r2')->get($donationType->greeting_card_template);
        } catch (\Throwable $e) {
            Log::warning('Greeting card template fetch failed', [
                'path' => $donationType->greeting_card_template,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if (! $templateBytes) {
            Log::warning('Greeting card template not found on R2', [
                'path' => $donationType->greeting_card_template,
            ]);

            return null;
        }

        $image = imagecreatefromstring($templateBytes);
        if (! $image) {
            Log::warning('Failed to decode template bytes into GD image', [
                'path' => $donationType->greeting_card_template,
            ]);

            return null;
        }

        $fontPath = $this->resolveFontPath();

        // Process each overlay
        foreach ($overlays as $overlay) {
            $this->applyOverlay($image, $overlay, $donation, $fontPath);
        }

        // Capture the rendered PNG into a byte string and write to R2 private.
        $outputPath = 'greeting-cards/'.$donation->id.'.png';

        ob_start();
        imagepng($image);
        $pngBytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('r2_private')->put($outputPath, $pngBytes);

        // Update the donation record
        $donation->update(['greeting_card_path' => $outputPath]);

        return $outputPath;
    }

    /**
     * Load a GD image resource from raw bytes. imagecreatefromstring auto-
     * detects PNG/JPG/GIF/WEBP/BMP so we no longer need per-extension dispatch.
     */
    private function loadImageFromBytes(string $bytes): \GdImage|false
    {
        return imagecreatefromstring($bytes);
    }

    /**
     * Resolve the best available font path for imagettftext.
     */
    private function resolveFontPath(): ?string
    {
        // Priority 1: Project resources/fonts directory
        $resourceFont = resource_path('fonts/DejaVuSans.ttf');
        if (file_exists($resourceFont)) {
            return $resourceFont;
        }

        // Priority 2: Vendor dompdf bundled font
        $vendorFont = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        if (file_exists($vendorFont)) {
            return $vendorFont;
        }

        // Priority 3: System font (Linux)
        $systemFont = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        if (file_exists($systemFont)) {
            return $systemFont;
        }

        // No TTF font available — will fallback to GD built-in
        return null;
    }

    /**
     * Apply a single overlay (text or image) onto the card.
     */
    private function applyOverlay(\GdImage $image, array $overlay, Donation $donation, ?string $fontPath): void
    {
        $type = $overlay['type'] ?? 'text';
        $fieldKey = $overlay['field_key'] ?? null;

        if (! $fieldKey) {
            return;
        }

        $value = $this->resolveFieldValue($fieldKey, $donation);
        if ($value === null || $value === '') {
            return;
        }

        if ($type === 'text') {
            // Route Gujarati/Hindi values (devotee names!) to a font with
            // their glyphs — DejaVu renders Indic text as tofu boxes.
            $this->applyTextOverlay(
                $image,
                $overlay,
                (string) $value,
                ScriptFont::forText((string) $value) ?? $fontPath,
            );
        } elseif ($type === 'image') {
            $this->applyImageOverlay($image, $overlay, (string) $value);
        }
    }

    /**
     * Resolve the value for a field key.
     * Keys starting with _ are special auto-fields.
     */
    private function resolveFieldValue(string $fieldKey, Donation $donation): ?string
    {
        if (str_starts_with($fieldKey, '_')) {
            return match ($fieldKey) {
                '_donor_name' => $donation->devotee?->name,
                '_amount' => "\u{20B9}".number_format((float) $donation->amount, 2),
                '_date' => now()->format('d M Y'),
                '_temple_name' => 'Shree Patadiya Hanumanji Seva Trust',
                default => null,
            };
        }

        $extraData = $donation->extra_data ?? [];

        return isset($extraData[$fieldKey]) ? (string) $extraData[$fieldKey] : null;
    }

    /**
     * Render a text overlay onto the image.
     */
    private function applyTextOverlay(\GdImage $image, array $overlay, string $text, ?string $fontPath): void
    {
        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $fontSize = (float) ($overlay['font_size'] ?? 16);
        $colorHex = $overlay['color'] ?? '#000000';
        $angle = (float) ($overlay['angle'] ?? 0);

        [$r, $g, $b] = $this->hexToRgb($colorHex);
        $color = imagecolorallocate($image, $r, $g, $b);

        $width = (int) ($overlay['width'] ?? 0);
        $angleForShaping = $angle;
        $colorHexForShaping = ltrim($colorHex, '#');

        // Indic text (Gujarati/Devanagari): GD cannot shape it — matras and
        // conjuncts garble (user-visible 2026-07-26). Render the whole block
        // via pango (ShapedText) and composite; the GD path below stays as
        // the fallback for Latin, rotated overlays, and hosts without pango.
        if ($angleForShaping === 0.0
            && ShapedText::needsShaping($text)
            && ShapedText::available()
        ) {
            $png = ShapedText::render($text, $fontSize, $colorHexForShaping, $width > 0 ? $width : null);
            if ($png instanceof \GdImage) {
                $dx = $width > 0 ? $x + (int) round(max(0, ($width - imagesx($png)) / 2)) : $x;
                imagealphablending($image, true);
                imagecopy($image, $png, $dx, $y, 0, 0, imagesx($png), imagesy($png));
                imagedestroy($png);

                return;
            }
        }

        if ($fontPath && file_exists($fontPath) && $width > 0) {
            // Centre each line within the overlay's width box, wrapping long
            // text onto new lines.
            $lines = $this->wrapText($text, $fontSize, $fontPath, $width);
            $lineHeight = $fontSize * 1.4;
            $ly = $y + $fontSize;
            foreach ($lines as $line) {
                $bbox = imagettfbbox($fontSize, 0, $fontPath, $line);
                $lineW = abs($bbox[2] - $bbox[0]);
                $lx = $x + (int) round(($width - $lineW) / 2);
                imagettftext($image, $fontSize, 0, $lx, (int) round($ly), $color, $fontPath, $line);
                $ly += $lineHeight;
            }
        } elseif ($fontPath && file_exists($fontPath)) {
            // GD's imagettftext Y is the text BASELINE (bottom of text),
            // but CSS top positions from the TOP of the element.
            // Add fontSize to Y to convert from top-left to baseline positioning.
            $baselineY = $y + (int) round($fontSize * 1.2);
            imagettftext($image, $fontSize, $angle, $x, $baselineY, $color, $fontPath, $text);
        } else {
            $builtinFont = min(5, max(1, (int) round($fontSize / 4)));
            imagestring($image, $builtinFont, $x, $y, $text, $color);
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

    /**
     * Place an image overlay (e.g. a photo from extra_data uploaded via the
     * donation form — those land in R2 public bucket since Phase 3a).
     */
    private function applyImageOverlay(\GdImage $image, array $overlay, string $storagePath): void
    {
        try {
            $bytes = Storage::disk('r2')->get($storagePath);
        } catch (\Throwable $e) {
            Log::warning('Greeting card overlay image fetch failed', [
                'path' => $storagePath,
                'error' => $e->getMessage(),
            ]);

            return;
        }
        if (! $bytes) {
            Log::warning('Greeting card overlay image not found on R2', ['path' => $storagePath]);

            return;
        }

        $overlayImage = $this->loadImageFromBytes($bytes);
        if (! $overlayImage) {
            return;
        }

        // Overlay images can be devotee-uploaded phone photos (donation extra
        // fields); GD drops EXIF orientation, so re-orient before compositing.
        $overlayImage = $this->applyExifOrientation($overlayImage, $bytes);

        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $width = (int) ($overlay['width'] ?? imagesx($overlayImage));
        $height = (int) ($overlay['height'] ?? imagesy($overlayImage));

        $srcWidth = imagesx($overlayImage);
        $srcHeight = imagesy($overlayImage);

        if (($overlay['shape'] ?? 'square') === 'circle') {
            $this->coverIntoCircle($image, $overlayImage, $x, $y, $width, $height, $srcWidth, $srcHeight);
        } else {
            $this->coverInto($image, $overlayImage, $x, $y, $width, $height, $srcWidth, $srcHeight);
        }
        imagedestroy($overlayImage);
    }

    /**
     * Composite $src into the $w×$h box at ($x,$y) using "cover" scaling: fill
     * the box while preserving aspect ratio, center-cropping the overflow, so
     * a user photo is never squeezed when the box shape differs from the photo.
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
     * Like coverInto(), but clips the photo to a circle/ellipse inscribed in
     * the $w×$h box (admin picked shape=circle in the overlay editor). The
     * photo is cover-scaled into an alpha-enabled temp canvas, pixels outside
     * the ellipse are made transparent (with a ~1.5px anti-aliased edge), and
     * the result is alpha-composited onto the card.
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

        // Same cover-crop math as coverInto(), targeted at the temp canvas.
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

        // Alpha-mask everything outside the inscribed ellipse. Feather the
        // edge over ~1.5px so the circle isn't jagged.
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
                    continue; // fully inside — keep the opaque photo pixel
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
     * Convert a hex color string to RGB values.
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        $r = $g = $b = 0;
        sscanf($hex, '%02x%02x%02x', $r, $g, $b);

        return [(int) $r, (int) $g, (int) $b];
    }

    /**
     * Re-orient a GD image per its source EXIF Orientation tag. GD drops EXIF
     * on load, so phone photos otherwise composite rotated/mirrored. Safe
     * no-op for images without an orientation tag (PNGs, already-upright JPEGs).
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
