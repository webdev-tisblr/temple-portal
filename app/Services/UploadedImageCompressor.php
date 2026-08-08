<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/**
 * Shrinks admin-uploaded photos before they reach R2.
 *
 * Every image the trust uploads comes straight off a phone — 3000-4000px
 * and several MB — while nothing on the site or in the app displays wider
 * than ~1200px. Storing the originals wastes R2 and, more visibly, makes
 * the gallery crawl on mobile data.
 *
 * Measured on a real darshan photo (3410x4096, 2.32 MB): scaling the long
 * edge to 2000px at JPEG q82 gives 1.00 MB, a 57% saving, in 0.39s.
 *
 * Deliberately FAIL-OPEN. compress() returns null on anything it cannot
 * handle and the caller stores the untouched original — a photo that is
 * merely larger than we would like beats an upload that errors.
 */
class UploadedImageCompressor
{
    /** Longest edge, in px, that any uploaded image is scaled down to. */
    public const MAX_EDGE = 2000;

    public const JPEG_QUALITY = 82;

    public const WEBP_QUALITY = 80;

    /**
     * Formats we re-encode. GIF is excluded because Intervention would
     * flatten an animation to its first frame, and SVG/PDF are not raster
     * images at all.
     */
    private const HANDLED = ['jpg', 'jpeg', 'png', 'webp'];

    private ?ImageManager $manager = null;

    /**
     * Compress the image at $path.
     *
     * @return array{bytes: string, extension: string, mime: string}|null
     *                                                                    null when the file should be stored exactly as uploaded.
     */
    public function compress(string $path, string $extension): ?array
    {
        $extension = strtolower($extension);

        if (! in_array($extension, self::HANDLED, true) || ! is_readable($path)) {
            return null;
        }

        try {
            $image = $this->manager()->decodePath($path);

            // Phone photos carry rotation in EXIF rather than in the pixels.
            // Re-encoding drops that tag, so bake it in first or portrait
            // shots come out sideways.
            $image->orient();

            $tooBig = $image->width() > self::MAX_EDGE || $image->height() > self::MAX_EDGE;

            if ($tooBig) {
                // scaleDown, not resize/cover: never upscales, never crops,
                // and keeps the aspect ratio on whichever edge is longer.
                $image->scaleDown(
                    width: $image->width() >= $image->height() ? self::MAX_EDGE : null,
                    height: $image->height() > $image->width() ? self::MAX_EDGE : null,
                );
            }

            [$encoder, $outExtension, $mime] = match ($extension) {
                'png' => [new PngEncoder, 'png', 'image/png'],
                'webp' => [new WebpEncoder(quality: self::WEBP_QUALITY), 'webp', 'image/webp'],
                default => [new JpegEncoder(quality: self::JPEG_QUALITY), 'jpg', 'image/jpeg'],
            };

            $bytes = (string) $image->encode($encoder);

            // Re-encoding can enlarge an already-optimised file (common with
            // PNG screenshots). Only keep the result if it actually helps and
            // we did not need the resize for display-size reasons.
            if (! $tooBig && strlen($bytes) >= (int) filesize($path)) {
                return null;
            }

            return ['bytes' => $bytes, 'extension' => $outExtension, 'mime' => $mime];
        } catch (\Throwable $e) {
            Log::warning('Upload compression skipped', [
                'path' => $path,
                'extension' => $extension,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Imagick where available (better resampling, lower peak memory on big
     * JPEGs), GD otherwise. Mirrors DarshanShareCardService.
     */
    private function manager(): ImageManager
    {
        if ($this->manager instanceof ImageManager) {
            return $this->manager;
        }

        if (extension_loaded('imagick')) {
            try {
                return $this->manager = new ImageManager(new ImagickDriver);
            } catch (\Throwable $e) {
                Log::warning('Imagick unavailable for upload compression, using GD', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->manager = new ImageManager(new GdDriver);
    }
}
