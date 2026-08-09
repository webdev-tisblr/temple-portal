<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;

/**
 * Builds the small renditions the mobile app and website list images with.
 *
 * WHY THIS EXISTS
 * ---------------
 * The Flutter app decodes whatever URL the API hands it, at the source
 * resolution. Seven production gallery originals are 199,756,800 px
 * (~200 MP) — 799 MB of decoded ARGB each. Android hands the process a
 * 192-256 MB heap on a mid-range phone, so the very first tile that
 * reaches the decoder is a hard SIGKILL with no Dart exception: exactly
 * the "app randomly force-closes" report. Even a well-behaved 2000x1500
 * original is 12 MB decoded, and the gallery lists 90 of them.
 *
 * Two derivatives fix that at the source:
 *
 *   thumbnail — 400 px longest edge. Grid/list tiles. A masonry tile is
 *               ~190 dp wide; at 3x that is 570 physical px, but tiles are
 *               cropped with BoxFit.cover so 400 px reads sharp and the
 *               memory saving (0.64 MB decoded) is what matters.
 *   medium    — 1000 px longest edge. The size the SHIPPED 1.4.8 app
 *               actually uses for tiles (its `thumbUrl` getter is
 *               `mediumUrl ?? thumbnailUrl ?? imageUrl`), and a good
 *               non-zoomed full-screen preview on a 1080 px phone.
 *               3.0 MB decoded — a 4x cut on today's originals and a
 *               266x cut on the 200 MP ones.
 *
 * Derivatives are REGENERABLE: they live beside the original under a
 * `derivatives/` prefix on the SAME (public) bucket, at a deterministic
 * key derived from the original's key. Nothing here ever writes to, or
 * deletes, the original — public-bucket originals are permanent trust
 * property (see CLAUDE.md).
 *
 * MEMORY SAFETY
 * -------------
 * Decoding is the dangerous step, not the scaling. Two defences:
 *
 *  1. Imagick (present on the VPS) gets a `jpeg:size` hint, which makes
 *     libjpeg decode at 1/2, 1/4 or 1/8 scale. A 200 MP JPEG asked for a
 *     1000 px box is read at 1/8 — ~1/64 the pixels, ~12 MB instead of
 *     ~800 MB. This is why the backfill runs comfortably inside the VPS's
 *     256 MB CLI limit.
 *  2. GD (local dev, and any host without Imagick) has no shrink-on-load,
 *     so the decode is wrapped in a temporary, bounded memory_limit lift
 *     sized from the real pixel count, restored in a finally block.
 *
 * Above MAX_SOURCE_PIXELS we refuse rather than gamble — a caller that
 * catches the exception keeps the original untouched.
 */
class ImageDerivativeService
{
    /** Longest edge, in px, of the `thumbnail` rendition. */
    public const THUMBNAIL_EDGE = 400;

    /** Longest edge, in px, of the `medium` rendition. */
    public const MEDIUM_EDGE = 1000;

    public const THUMBNAIL_QUALITY = 74;

    public const MEDIUM_QUALITY = 82;

    /**
     * Hard ceiling on a source image we will even attempt to decode.
     * 256 MP clears the 199.7 MP production outliers with room to spare;
     * anything larger is a decompression bomb, not a photograph.
     */
    public const MAX_SOURCE_PIXELS = 268_435_456;

    /**
     * Ceiling on the temporary memory_limit lift used for the GD path.
     * 2 GB covers a 256 MP decode at the 6 bytes/px working estimate.
     */
    public const MAX_MEMORY_BYTES = 2_048 * 1024 * 1024;

    /** Key prefix, inside the original's own directory, for renditions. */
    public const DERIVATIVE_DIR = 'derivatives';

    /** Variant name => [longest edge px, JPEG quality]. */
    public const VARIANTS = [
        'medium' => [self::MEDIUM_EDGE, self::MEDIUM_QUALITY],
        'thumbnail' => [self::THUMBNAIL_EDGE, self::THUMBNAIL_QUALITY],
    ];

    private ?ImageManager $manager = null;

    private bool $usesImagick = false;

    /**
     * Deterministic storage key for one rendition of $sourceKey.
     *
     * `gallery/01KZG4ET9D.jpg` → `gallery/derivatives/01KZG4ET9D_thumbnail.jpg`
     *
     * Determinism is what makes the backfill idempotent and safe to re-run:
     * regenerating writes over the same object, the model column does not
     * change, and HasManagedImages therefore never cascade-deletes the file
     * we just uploaded.
     */
    public function derivativeKey(string $sourceKey, string $variant): string
    {
        $dir = trim((string) pathinfo($sourceKey, PATHINFO_DIRNAME), '.\\/');
        $name = pathinfo($sourceKey, PATHINFO_FILENAME);

        $prefix = $dir === '' ? self::DERIVATIVE_DIR : $dir.'/'.self::DERIVATIVE_DIR;

        return $prefix.'/'.$name.'_'.$variant.'.jpg';
    }

    /**
     * Build every rendition of the object at $sourceKey and store them on
     * the same disk.
     *
     * @return array<string, string> variant => stored key
     *
     * @throws RuntimeException when the source is missing or undecodable
     */
    public function generate(string $sourceKey, string $disk = 'r2'): array
    {
        $storage = Storage::disk($disk);

        $stream = $storage->readStream($sourceKey);
        if ($stream === null || $stream === false) {
            throw new RuntimeException("Source object missing on [{$disk}]: {$sourceKey}");
        }

        // Stream to a temp file rather than ->get(): a 200 MP JPEG is tens
        // of MB and getimagesize()/Imagick both want a real path anyway.
        $tmp = tempnam(sys_get_temp_dir(), 'derivative_');
        if ($tmp === false) {
            fclose($stream);
            throw new RuntimeException('Unable to allocate a temp file for derivative generation.');
        }

        try {
            $out = fopen($tmp, 'wb');
            if ($out === false) {
                throw new RuntimeException("Unable to open temp file: {$tmp}");
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);

            return $this->generateFromFile($tmp, $sourceKey, $disk);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($tmp);
        }
    }

    /**
     * Same as generate(), but from an already-local file — used by the
     * upload path, which has the bytes on disk and should not round-trip
     * through R2 just to read them back.
     *
     * @return array<string, string> variant => stored key
     */
    public function generateFromFile(string $localPath, string $sourceKey, string $disk = 'r2'): array
    {
        // Decode once, at the LARGEST rendition we need, then step down.
        // Scaling 1000 → 400 costs almost nothing next to a second decode.
        $image = $this->decodePath($localPath, self::MEDIUM_EDGE);

        // Phone photos carry rotation in EXIF. A re-encoded derivative has
        // no EXIF, so bake the orientation in or portrait shots come out
        // sideways in the app while the original looks fine.
        $image->orient();

        $storage = Storage::disk($disk);
        $keys = [];

        foreach (self::VARIANTS as $variant => [$edge, $quality]) {
            // scaleDown never upscales: a source already smaller than the
            // box is copied through at its own size, which is correct —
            // we want a cheap decode, not an artificially blurry one.
            $image->scaleDown(width: $edge, height: $edge);

            $bytes = (string) $image->encode(new JpegEncoder(quality: $quality));
            $key = $this->derivativeKey($sourceKey, $variant);

            $storage->put($key, $bytes, [
                'visibility' => 'public',
                'ContentType' => 'image/jpeg',
            ]);

            $keys[$variant] = $key;
            unset($bytes);
        }

        return $keys;
    }

    /**
     * Memory-safe decode. $targetEdge is the largest box the caller will
     * ever need, and is used as the shrink-on-load hint.
     *
     * @throws RuntimeException on a decompression-bomb source
     */
    public function decodePath(string $path, int $targetEdge): ImageInterface
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Unreadable image: {$path}");
        }

        $info = @getimagesize($path);
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $pixels = $width * $height;

        $manager = $this->manager();

        // Path 1 — Imagick + JPEG. Shrink-on-load makes peak memory a
        // function of $targetEdge, NOT of the source, so there is no size
        // at which this path is unsafe and no ceiling is applied. This is
        // the path production takes (the VPS has imagick).
        if ($this->usesImagick && $pixels > 0 && ($info['mime'] ?? '') === 'image/jpeg') {
            try {
                $imagick = new \Imagick;
                // libjpeg picks the smallest 1/1, 1/2, 1/4 or 1/8 DCT scale
                // that still covers this box — a 200 MP source read for a
                // 1000 px box costs ~1/64 the pixels.
                $imagick->setOption('jpeg:size', $targetEdge.'x'.$targetEdge);
                $imagick->readImage($path);

                return $manager->decode($imagick);
            } catch (\Throwable $e) {
                Log::warning('Imagick shrink-on-load decode failed, falling back', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Path 2 — full-resolution decode (GD, or a non-JPEG source). Peak
        // memory scales with the source, so the bomb ceiling applies here.
        if ($pixels > self::MAX_SOURCE_PIXELS) {
            throw new RuntimeException(sprintf(
                'Refusing to decode %s: %dx%d (%.1f MP) exceeds the %d MP full-decode ceiling.',
                basename($path),
                $width,
                $height,
                $pixels / 1_000_000,
                (int) (self::MAX_SOURCE_PIXELS / 1_000_000),
            ));
        }

        return $this->withMemoryFor($pixels, fn (): ImageInterface => $manager->decodePath($path));
    }

    /**
     * Run $fn with a memory_limit big enough for a full-resolution decode
     * of $pixels, then put the limit back exactly as it was.
     *
     * 6 bytes/px is the working estimate: 4 for the truecolor bitmap GD
     * materialises, plus row buffers and the encoder's scratch space.
     */
    private function withMemoryFor(int $pixels, Closure $fn): mixed
    {
        $needed = $pixels * 6 + 64 * 1024 * 1024;
        $current = $this->memoryLimitBytes();

        if ($current === -1 || $needed <= $current) {
            return $fn();
        }

        if ($needed > self::MAX_MEMORY_BYTES) {
            throw new RuntimeException(sprintf(
                'Decoding this image needs ~%d MB, above the %d MB ceiling.',
                (int) ($needed / 1048576),
                (int) (self::MAX_MEMORY_BYTES / 1048576),
            ));
        }

        $restore = (string) ini_get('memory_limit');
        ini_set('memory_limit', (int) ceil($needed / 1048576).'M');

        try {
            return $fn();
        } finally {
            ini_set('memory_limit', $restore);
        }
    }

    /** Current memory_limit in bytes, or -1 when unlimited. */
    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));

        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    /**
     * Imagick where available (shrink-on-load, better resampling), GD
     * otherwise. Mirrors DarshanShareCardService / UploadedImageCompressor.
     */
    public function manager(): ImageManager
    {
        if ($this->manager instanceof ImageManager) {
            return $this->manager;
        }

        if (extension_loaded('imagick')) {
            try {
                $this->usesImagick = true;

                return $this->manager = new ImageManager(new ImagickDriver);
            } catch (\Throwable $e) {
                $this->usesImagick = false;
                Log::warning('Imagick unavailable for derivatives, using GD', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->usesImagick = false;

        return $this->manager = new ImageManager(new GdDriver);
    }
}
