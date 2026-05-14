<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyDarshanPhoto;
use App\Models\Devotee;
use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Geometry\Factories\CircleFactory;
use Intervention\Image\Geometry\Factories\RectangleFactory;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Intervention\Image\Typography\FontFactory;

/**
 * Renders a personalised "Daily Darshan" share card on top of today's
 * darshan photo. Output goes to the public R2 bucket so the URL is
 * cacheable on the CDN and shareable from web + Flutter.
 *
 * Two output dimensions:
 *   • story  (1080×1920) — WhatsApp Status, Instagram / Facebook Story
 *   • square (1080×1080) — Instagram feed, WhatsApp DP, general post
 *
 * Personalisation:
 *   • Devotee name (optional — falls back to a generic blessing line)
 *   • Devotee circular avatar (optional — only when profile_photo_path is set)
 *   • Trust branding + temple URL + today's date
 *
 * Caching: same (photo × devotee × format) renders into a stable R2
 * path, so a repeat request returns the already-uploaded URL (no
 * recompute) and Cloudflare's edge cache picks it up on first hit.
 * New photo or new devotee → new path → new render. Old cards stay on
 * R2 until the maintenance command sweeps them.
 *
 * Library: Intervention Image v4 with the GD driver. GD ships with PHP
 * by default; Imagick would give sharper text but isn't always available
 * on Hostinger shared hosting.
 */
class DarshanShareCardService
{
    public const FORMAT_STORY = 'story';
    public const FORMAT_SQUARE = 'square';

    /** Public R2 prefix; cards live next to the source photos. */
    private const STORAGE_PREFIX = 'daily-darshan-cards';

    /** JPEG quality — 88 keeps file size under ~250KB at 1080×1920. */
    private const JPEG_QUALITY = 88;

    /** Brand palette — derived from the temple's saffron-and-gold identity. */
    private const C_SAFFRON_DEEP = '#d4711c';
    private const C_SAFFRON = '#e87a1a';
    private const C_GOLD = '#d4a017';
    private const C_CREAM = '#fff8e7';
    private const C_INK = '#1a1a1a';
    private const C_INK_MUTED = '#5a4a3a';
    private const C_WHITE = '#ffffff';

    private ImageManager $manager;

    public function __construct()
    {
        // Imagick gives full HarfBuzz shaping for complex Gujarati
        // conjuncts (શ્રી, ્ય, ્ર). GD's imagettftext renders Indic glyphs
        // in raw sequence without OpenType shaping — small text with many
        // conjuncts shows up as tofu boxes. We prefer Imagick when it's
        // installed and fall back to GD if not (Hostinger shared hosting
        // doesn't always ship the imagick extension).
        if (extension_loaded('imagick')) {
            try {
                $this->manager = new ImageManager(new ImagickDriver());
                return;
            } catch (\Throwable $e) {
                Log::warning('DarshanShareCard: Imagick driver init failed — falling back to GD', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->manager = new ImageManager(new GdDriver());
    }

    /**
     * Build (or fetch from cache) the personalised share card.
     *
     * @return array{url:string, format:string, width:int, height:int, cached:bool}
     */
    public function generate(
        DailyDarshanPhoto $photo,
        ?Devotee $devotee = null,
        string $format = self::FORMAT_STORY,
    ): array {
        $format = in_array($format, [self::FORMAT_STORY, self::FORMAT_SQUARE], true)
            ? $format
            : self::FORMAT_STORY;

        [$width, $height] = $format === self::FORMAT_STORY ? [1080, 1920] : [1080, 1080];

        $storagePath = $this->storagePathFor($photo, $devotee, $format);
        $disk = Storage::disk('r2');

        // Idempotent: skip the render when we already have this card.
        if ($disk->exists($storagePath)) {
            return [
                'url' => $disk->url($storagePath),
                'format' => $format,
                'width' => $width,
                'height' => $height,
                'cached' => true,
            ];
        }

        $canvas = $this->render($photo, $devotee, $format, $width, $height);
        $bytes = (string) $canvas->encode(new JpegEncoder(quality: self::JPEG_QUALITY));

        $disk->put($storagePath, $bytes, [
            'visibility' => 'public',
            'ContentType' => 'image/jpeg',
            'CacheControl' => 'public, max-age=2592000', // 30 days at the edge
        ]);

        return [
            'url' => $disk->url($storagePath),
            'format' => $format,
            'width' => $width,
            'height' => $height,
            'cached' => false,
        ];
    }

    /**
     * Sweep cards older than $days. Wire to a daily scheduled command.
     * Cards are regenerable on demand, so retention only matters for
     * storage cost (R2 charges per GB-month).
     */
    public function cleanup(int $days = 30): int
    {
        $disk = Storage::disk('r2');
        $cutoff = now()->subDays($days)->timestamp;
        $deleted = 0;

        foreach ($disk->allFiles(self::STORAGE_PREFIX) as $path) {
            try {
                if ($disk->lastModified($path) < $cutoff) {
                    $disk->delete($path);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                Log::warning('DarshanShareCard: cleanup failed for path', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $deleted;
    }

    // ------------------------------------------------------------------
    // Rendering pipeline
    // ------------------------------------------------------------------

    private function render(
        DailyDarshanPhoto $photo,
        ?Devotee $devotee,
        string $format,
        int $width,
        int $height,
    ): ImageInterface {
        // 1. Cream canvas (warm devotional base, never pure white).
        $canvas = $this->manager->createImage($width, $height)->fill(self::C_CREAM);

        // 2. Saffron top band + cream footer band — the photo sits inside
        //    a framed "card within a card". Story format gets taller bands.
        $headerHeight = $format === self::FORMAT_STORY ? 180 : 130;
        $footerHeight = $format === self::FORMAT_STORY ? 460 : 320;

        $this->drawHeaderBand($canvas, $width, $headerHeight);
        $this->drawFooterBand($canvas, $width, $height, $footerHeight);

        // 3. Photo composite — cover-cropped + framed.
        $photoArea = [
            'x' => 36,
            'y' => $headerHeight + 24,
            'w' => $width - 72,
            'h' => $height - $headerHeight - $footerHeight - 48,
        ];
        $this->drawDarshanPhoto($canvas, $photo, $photoArea);

        // 4. Trust branding in the header band.
        $trustName = SystemSetting::getValue('trust_name', 'શ્રી પાતળિયા હનુમાનજી સેવા ટ્રસ્ટ');
        $this->drawHeaderText($canvas, $width, $headerHeight, $trustName);

        // 5. Footer composition — blessing, optional devotee, date, URL.
        $this->drawFooter($canvas, $devotee, $width, $height, $footerHeight, $format);

        return $canvas;
    }

    private function drawHeaderBand(ImageInterface $canvas, int $width, int $height): void
    {
        // Solid saffron band plus a 4px gold pinstripe along the bottom
        // edge — mimics a sari border, visible at thumbnail size, premium
        // at full res.
        $canvas->drawRectangle(function (RectangleFactory $r) use ($width, $height) {
            $r->at(0, 0);
            $r->size($width, $height);
            $r->background(self::C_SAFFRON);
        });
        $canvas->drawRectangle(function (RectangleFactory $r) use ($width, $height) {
            $r->at(0, $height - 4);
            $r->size($width, 4);
            $r->background(self::C_GOLD);
        });
    }

    private function drawFooterBand(ImageInterface $canvas, int $width, int $height, int $bandHeight): void
    {
        $top = $height - $bandHeight;
        $canvas->drawRectangle(function (RectangleFactory $r) use ($top, $width, $bandHeight) {
            $r->at(0, $top);
            $r->size($width, $bandHeight);
            $r->background(self::C_CREAM);
        });
        // Gold separator stripe between photo and footer.
        $canvas->drawRectangle(function (RectangleFactory $r) use ($top, $width) {
            $r->at(0, $top - 4);
            $r->size($width, 4);
            $r->background(self::C_GOLD);
        });
    }

    private function drawHeaderText(ImageInterface $canvas, int $width, int $headerHeight, string $trustName): void
    {
        $centerY = intval($headerHeight / 2);

        // ૐ glyph on the left as a vertical anchor.
        $canvas->text('ૐ', 76, $centerY + 18, function (FontFactory $font) {
            $font->filename($this->gujaratiFont(bold: true));
            $font->size(72);
            $font->color(self::C_WHITE);
            $font->align('left', 'center');
        });

        // Trust name centred across the band. Font is picked by script
        // — NotoSansGujarati doesn't carry Latin glyphs, so the English
        // SystemSetting value (e.g. "Shree Pataliya Hanumanji Seva Trust")
        // rendered as nothing when forced through the Gujarati font.
        // Earlier bug: blank saffron header on production until this fix.
        $headerFont = $this->pickFontForText($trustName, bold: true);
        $canvas->text($trustName, intval($width / 2) + 30, $centerY, function (FontFactory $font) use ($headerFont) {
            $font->filename($headerFont);
            $font->size(40);
            $font->color(self::C_WHITE);
            $font->align('center', 'center');
            $font->lineHeight(1.2);
        });
    }

    /**
     * Pick a font file whose glyph coverage matches the script in $text.
     *
     * NotoSansGujarati supports only the Gujarati Unicode block. DejaVuSans
     * covers Latin + extended punctuation but nothing Indic. A real
     * font-fallback chain would need Imagick's font config; we approximate
     * by detecting the first Gujarati codepoint and routing accordingly.
     */
    private function pickFontForText(string $text, bool $bold): string
    {
        if (preg_match('/[\x{0A80}-\x{0AFF}]/u', $text)) {
            return $this->gujaratiFont(bold: $bold);
        }
        return $this->englishFont();
    }

    /**
     * Cover-crops the daily darshan photo into the target rectangle and
     * inserts it. A 6px gold frame around the inserted image gives a
     * framed-poster feel.
     */
    private function drawDarshanPhoto(ImageInterface $canvas, DailyDarshanPhoto $photo, array $area): void
    {
        try {
            $bytes = Storage::disk('r2')->get($photo->image_path);
            if ($bytes === null || $bytes === '') {
                $this->drawPhotoFallback($canvas, $area);
                return;
            }
            $img = $this->manager->decodeBinary($bytes)->cover($area['w'], $area['h']);
            $canvas->insert($img, $area['x'], $area['y']);
        } catch (\Throwable $e) {
            Log::error('DarshanShareCard: photo load failed', [
                'image_path' => $photo->image_path,
                'error' => $e->getMessage(),
            ]);
            $this->drawPhotoFallback($canvas, $area);
            return;
        }

        // Inner gold border — 4 thin rectangles around the image perimeter.
        $b = 6;
        $strokes = [
            [$area['x'], $area['y'], $area['w'], $b],                          // top
            [$area['x'], $area['y'] + $area['h'] - $b, $area['w'], $b],        // bottom
            [$area['x'], $area['y'], $b, $area['h']],                          // left
            [$area['x'] + $area['w'] - $b, $area['y'], $b, $area['h']],        // right
        ];
        foreach ($strokes as [$sx, $sy, $sw, $sh]) {
            $canvas->drawRectangle(function (RectangleFactory $r) use ($sx, $sy, $sw, $sh) {
                $r->at($sx, $sy);
                $r->size($sw, $sh);
                $r->background(self::C_GOLD);
            });
        }
    }

    private function drawPhotoFallback(ImageInterface $canvas, array $area): void
    {
        $canvas->drawRectangle(function (RectangleFactory $r) use ($area) {
            $r->at($area['x'], $area['y']);
            $r->size($area['w'], $area['h']);
            $r->background(self::C_SAFFRON_DEEP);
        });
        $cx = intval($area['x'] + $area['w'] / 2);
        $cy = intval($area['y'] + $area['h'] / 2);
        $canvas->text('🕉', $cx, $cy, function (FontFactory $font) {
            $font->filename($this->englishFont());
            $font->size(220);
            $font->color(self::C_GOLD);
            $font->align('center', 'center');
        });
    }

    private function drawFooter(
        ImageInterface $canvas,
        ?Devotee $devotee,
        int $width,
        int $height,
        int $footerHeight,
        string $format,
    ): void {
        $footerTop = $height - $footerHeight;
        $cx = intval($width / 2);

        // 1. "જય શ્રી રામ" — the centrepiece blessing.
        $blessingY = $footerTop + ($format === self::FORMAT_STORY ? 110 : 80);
        $canvas->text('જય શ્રી રામ', $cx, $blessingY, function (FontFactory $font) use ($format) {
            $font->filename($this->gujaratiFont(bold: true));
            $font->size($format === self::FORMAT_STORY ? 110 : 80);
            $font->color(self::C_SAFFRON_DEEP);
            $font->align('center', 'center');
        });

        // 2. Decorative divider — gold dashes on either side of a centred dot.
        $divY = $blessingY + ($format === self::FORMAT_STORY ? 80 : 55);
        $divLen = 200;
        $canvas->drawRectangle(function (RectangleFactory $r) use ($cx, $divLen, $divY) {
            $r->at($cx - $divLen - 30, $divY);
            $r->size($divLen, 3);
            $r->background(self::C_GOLD);
        });
        $canvas->drawRectangle(function (RectangleFactory $r) use ($cx, $divLen, $divY) {
            $r->at($cx + 30, $divY);
            $r->size($divLen, 3);
            $r->background(self::C_GOLD);
        });
        $canvas->drawCircle(function (CircleFactory $c) use ($cx, $divY) {
            $c->at($cx, $divY + 1);
            $c->radius(8);
            $c->background(self::C_GOLD);
        });

        // 3. Devotee personalisation — name + optional circular avatar.
        $personalY = $divY + ($format === self::FORMAT_STORY ? 90 : 65);
        $this->drawDevoteeBlock($canvas, $devotee, $cx, $personalY, $format);

        // 4. Footer meta — date + URL.
        $metaY = $height - 60;
        $today = Carbon::now()->locale('en')->translatedFormat('d M Y');
        $canvas->text($today . '  •  patadiyahanumanji.com', $cx, $metaY, function (FontFactory $font) {
            $font->filename($this->englishFont());
            $font->size(28);
            $font->color(self::C_INK_MUTED);
            $font->align('center', 'center');
        });
    }

    private function drawDevoteeBlock(
        ImageInterface $canvas,
        ?Devotee $devotee,
        int $cx,
        int $y,
        string $format,
    ): void {
        if ($devotee === null || empty($devotee->name)) {
            // Anonymous variant — a soft "With prayers" line keeps the
            // footer balanced for non-logged-in users.
            $canvas->text('પ્રાર્થના સહિત', $cx, $y, function (FontFactory $font) use ($format) {
                $font->filename($this->gujaratiFont(bold: false));
                $font->size($format === self::FORMAT_STORY ? 38 : 30);
                $font->color(self::C_INK);
                $font->align('center', 'center');
            });
            return;
        }

        $avatarSize = $format === self::FORMAT_STORY ? 96 : 72;
        $avatar = $this->loadAvatar($devotee, $avatarSize);
        $name = $devotee->name;
        $fontSize = $format === self::FORMAT_STORY ? 44 : 34;

        // Script-aware font for the name — same fix as the header. Most
        // devotees register with a Latin-spelled name ("Harsh", "Meet")
        // and the Gujarati font has no Latin glyphs, so those previously
        // rendered as nothing.
        $nameFont = $this->pickFontForText($name, bold: true);

        // GD's font subsystem has no boundary-box accessor exposed
        // through Intervention v4, so we estimate name width at
        // 0.55× pt size per glyph. Close enough to centre the
        // (avatar + name) pair without visible drift.
        $estimatedNameWidth = (int) (mb_strlen($name) * $fontSize * 0.55);

        if ($avatar !== null) {
            $totalWidth = $avatarSize + 24 + $estimatedNameWidth;
            $startX = $cx - intval($totalWidth / 2);
            $canvas->insert($avatar, $startX, $y - intval($avatarSize / 2));

            $canvas->text($name, $startX + $avatarSize + 24, $y, function (FontFactory $font) use ($fontSize, $nameFont) {
                $font->filename($nameFont);
                $font->size($fontSize);
                $font->color(self::C_INK);
                $font->align('left', 'center');
            });
        } else {
            // Defensive log — drawDevoteeBlock fell back to centred-name
            // only path, which means loadAvatar returned null even
            // though we have a devotee. Should never happen now that
            // the temple-logo fallback is in place. If it logs, the
            // logo file is missing on disk.
            Log::warning('DarshanShareCard: avatar null despite devotee — both photo + logo failed to load', [
                'devotee_id' => $devotee->getKey(),
                'has_profile_photo' => ! empty($devotee->profile_photo_path),
            ]);
            $canvas->text($name, $cx, $y, function (FontFactory $font) use ($fontSize, $nameFont) {
                $font->filename($nameFont);
                $font->size($fontSize);
                $font->color(self::C_INK);
                $font->align('center', 'center');
            });
        }
    }

    /**
     * Build a circular avatar with a gold ring for the devotee block.
     *
     * Source priority:
     *   1. devotee->profile_photo_path on R2 (user's own selfie)
     *   2. The temple logo bundled at public/images/shree-pataliya-hanumanji-logo.png
     *      (so every logged-in user gets a personal-looking card even
     *       without uploading a photo)
     *   3. null only when both fail to load (defensive — caller falls
     *      back to centred name)
     *
     * The visible "round" shape comes from masking the four corners
     * with the footer cream colour AFTER the cover-crop. Imagick handles
     * this beautifully via alpha; GD's imagettftext doesn't expose alpha
     * masks but corner-fill triangles approximate it well at ≤ 96px.
     */
    private function loadAvatar(Devotee $devotee, int $size): ?ImageInterface
    {
        $avatar = $this->loadDevoteePhoto($devotee, $size)
            ?? $this->loadTempleLogo($size);

        if ($avatar === null) {
            return null;
        }

        $this->maskCorners($avatar, $size);
        $this->stampGoldRing($avatar, $size);

        return $avatar;
    }

    /** Load the devotee's uploaded profile photo from R2, cover-cropped. */
    private function loadDevoteePhoto(Devotee $devotee, int $size): ?ImageInterface
    {
        $path = $devotee->profile_photo_path;
        if (empty($path)) {
            return null;
        }

        try {
            $bytes = Storage::disk('r2')->get($path);
            if ($bytes === null || $bytes === '') {
                return null;
            }
            return $this->manager->decodeBinary($bytes)->cover($size, $size);
        } catch (\Throwable $e) {
            Log::info('DarshanShareCard: devotee photo load skipped', [
                'devotee_id' => $devotee->getKey(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /** Load the bundled temple logo as the avatar fallback. */
    private function loadTempleLogo(int $size): ?ImageInterface
    {
        $logoPath = public_path('images/shree-pataliya-hanumanji-logo.png');
        if (! file_exists($logoPath)) {
            Log::warning('DarshanShareCard: temple logo file missing', ['path' => $logoPath]);
            return null;
        }
        try {
            return $this->manager->decodePath($logoPath)->cover($size, $size);
        } catch (\Throwable $e) {
            Log::warning('DarshanShareCard: temple logo decode failed', [
                'path' => $logoPath,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Approximate a circular crop by overdrawing the four corners with
     * the footer cream colour. Cheap, works with both Imagick and GD.
     * Visually indistinguishable from a real alpha mask at ≤ 96px.
     *
     * Geometry: for each corner, an opaque triangular wedge is faked by
     * a quarter-circle drawn at the inverted corner with a radius equal
     * to half the avatar size. drawCircle fills the entire ellipse so we
     * stamp it OUTSIDE the avatar bounds (offset by -r/2) — only the
     * inner quadrant overlaps the avatar.
     */
    private function maskCorners(ImageInterface $avatar, int $size): void
    {
        $r = intval($size / 2);
        // (cx, cy) for each corner-quadrant circle centered just outside
        // the avatar at that corner.
        $corners = [
            [0, 0],            // top-left
            [$size, 0],        // top-right
            [0, $size],        // bottom-left
            [$size, $size],    // bottom-right
        ];
        foreach ($corners as [$cx, $cy]) {
            $avatar->drawCircle(function (CircleFactory $c) use ($cx, $cy, $r) {
                $c->at($cx, $cy);
                $c->radius($r);
                $c->background(self::C_CREAM);
            });
        }
    }

    /** Gold ring along the circular edge — the framed-avatar look. */
    private function stampGoldRing(ImageInterface $avatar, int $size): void
    {
        $avatar->drawCircle(function (CircleFactory $c) use ($size) {
            $c->at(intval($size / 2), intval($size / 2));
            $c->radius(intval($size / 2) - 2);
            $c->background('rgba(0,0,0,0)');
            $c->border(self::C_GOLD, 4);
        });
    }

    /** Deterministic R2 path so identical inputs collapse to one file. */
    private function storagePathFor(DailyDarshanPhoto $photo, ?Devotee $devotee, string $format): string
    {
        $devoteeSegment = $devotee ? 'd' . $devotee->getKey() : 'anon';
        $date = $photo->captured_on?->format('Y-m-d') ?? now()->format('Y-m-d');

        // Hash includes the photo's updated_at so re-uploading the source
        // image invalidates the cached card automatically. The trailing
        // version suffix is bumped manually whenever the rendering pipeline
        // produces materially different output (driver swap, layout shift,
        // typography change) — v2 invalidates the pre-Imagick-fallback
        // cards that had tofu boxes for the trust-name header.
        $hash = substr(sha1("{$photo->id}|{$photo->updated_at?->timestamp}|{$devoteeSegment}|{$format}|v5"), 0, 12);

        return self::STORAGE_PREFIX . "/{$date}/{$devoteeSegment}-{$format}-{$hash}.jpg";
    }

    private function gujaratiFont(bool $bold): string
    {
        return resource_path($bold
            ? 'fonts/NotoSansGujarati-Bold.ttf'
            : 'fonts/NotoSansGujarati-Regular.ttf'
        );
    }

    private function englishFont(): string
    {
        // Latin numerals + URL — DejaVuSans ships with dompdf and is
        // always available. The Gujarati font would render Latin glyphs
        // too but the metrics look thinner; DejaVu keeps the meta line
        // balanced.
        $vendor = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        if (file_exists($vendor)) {
            return $vendor;
        }
        return $this->gujaratiFont(bold: false);
    }
}
