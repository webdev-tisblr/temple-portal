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

        // Load the template image from public storage
        $templatePath = Storage::disk('public')->path($donationType->greeting_card_template);
        if (! file_exists($templatePath)) {
            Log::warning('Greeting card template file not found', ['path' => $templatePath]);

            return null;
        }

        $image = $this->loadImage($templatePath);
        if (! $image) {
            Log::warning('Failed to create GD image from template', ['path' => $templatePath]);

            return null;
        }

        $fontPath = $this->resolveFontPath();

        // Process each overlay
        foreach ($overlays as $overlay) {
            $this->applyOverlay($image, $overlay, $donation, $fontPath);
        }

        // Save the final image
        $outputDir = 'greeting-cards';
        $outputPath = $outputDir . '/' . $donation->id . '.png';

        $absoluteDir = Storage::disk('local')->path($outputDir);
        if (! is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        $absolutePath = Storage::disk('local')->path($outputPath);
        imagepng($image, $absolutePath);
        imagedestroy($image);

        // Update the donation record
        $donation->update(['greeting_card_path' => $outputPath]);

        return $outputPath;
    }

    /**
     * Load a GD image resource from a file path (supports PNG and JPG).
     */
    private function loadImage(string $path): \GdImage|false
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'png' => imagecreatefrompng($path),
            'jpg', 'jpeg' => imagecreatefromjpeg($path),
            'webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
            'gif' => imagecreatefromgif($path),
            'bmp' => function_exists('imagecreatefrombmp') ? imagecreatefrombmp($path) : false,
            default => false,
        };
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
     * Place an image overlay (e.g. a photo from extra_data).
     */
    private function applyImageOverlay(\GdImage $image, array $overlay, string $storagePath): void
    {
        $absolutePath = Storage::disk('public')->path($storagePath);
        if (! file_exists($absolutePath)) {
            Log::warning('Greeting card overlay image not found', ['path' => $absolutePath]);

            return;
        }

        $overlayImage = $this->loadImage($absolutePath);
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
