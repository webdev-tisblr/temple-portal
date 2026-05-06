<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Donation;
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
        $outputPath = 'greeting-cards/' . $donation->id . '.png';

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
            $this->applyTextOverlay($image, $overlay, (string) $value, $fontPath);
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
                '_amount' => "\u{20B9}" . number_format((float) $donation->amount, 2),
                '_date' => now()->format('d M Y'),
                '_temple_name' => 'Shree Pataliya Hanumanji Seva Trust',
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

        if ($fontPath && file_exists($fontPath)) {
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

        $x = (int) ($overlay['x'] ?? 0);
        $y = (int) ($overlay['y'] ?? 0);
        $width = (int) ($overlay['width'] ?? imagesx($overlayImage));
        $height = (int) ($overlay['height'] ?? imagesy($overlayImage));

        $srcWidth = imagesx($overlayImage);
        $srcHeight = imagesy($overlayImage);

        imagecopyresampled($image, $overlayImage, $x, $y, 0, 0, $width, $height, $srcWidth, $srcHeight);
        imagedestroy($overlayImage);
    }

    /**
     * Convert a hex color string to RGB values.
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = $g = $b = 0;
        sscanf($hex, '%02x%02x%02x', $r, $g, $b);

        return [(int) $r, (int) $g, (int) $b];
    }
}
