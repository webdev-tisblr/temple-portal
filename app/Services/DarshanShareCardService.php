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
    private const C_GOLD = '#c89030';      // body accent
    private const C_GOLD_BRIGHT = '#e6b948'; // blessing / highlight text
    private const C_BURGUNDY = '#4a1a22';  // main card background
    private const C_BURGUNDY_DEEP = '#37121a'; // ornamental shadows
    private const C_CREAM = '#fff8e7';     // (legacy — used for footer band, retained for fallback paths)
    private const C_CREAM_BODY = '#e8d8b8'; // text colour on burgundy
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
        // 1. Deep burgundy canvas — the new devotional base. Replaces the
        //    earlier cream so the gold ornaments + saffron header pop.
        $canvas = $this->manager->createImage($width, $height)
            ->fill(self::C_BURGUNDY);

        // 2. Saffron top band (trust branding).
        $headerHeight = $format === self::FORMAT_STORY ? 180 : 130;
        $this->drawHeaderBand($canvas, $width, $headerHeight);

        $trustName = SystemSetting::getValue('trust_name', 'Shree Pataliya Hanumanji Seva Trust');
        $this->drawHeaderText($canvas, $width, $headerHeight, $trustName);

        // 3. Circular darshan photo with concentric gold rings — replaces
        //    the rectangular gold-frame layout.
        $photoRadius = $format === self::FORMAT_STORY ? 430 : 290;
        $photoCenter = [
            'x' => intval($width / 2),
            'y' => $headerHeight + 60 + $photoRadius,
        ];
        $this->drawCircularDarshanPhoto($canvas, $photo, $photoCenter, $photoRadius);

        // 4. "જય શ્રી રામ" blessing in gold, with a decorative divider.
        $blessingY = $photoCenter['y'] + $photoRadius
            + ($format === self::FORMAT_STORY ? 140 : 90);
        $this->drawBlessing($canvas, $width, $blessingY, $format);

        // 5. Devotee block (avatar on the left + multiline text on the right).
        $devoteeBlockY = $blessingY + ($format === self::FORMAT_STORY ? 200 : 140);
        $this->drawDevoteeBlock($canvas, $devotee, $width, $devoteeBlockY, $format);

        // 6. Footer meta — date + URL.
        $this->drawFooterMeta($canvas, $width, $height);

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

    // drawFooterBand removed in the burgundy redesign — the body is now
    // a single continuous burgundy field. The earlier cream footer band
    // was needed only when the canvas was split into header / cream
    // photo area / cream footer.

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
     * Cover-crop the darshan photo into a square the size of the photo
     * circle's diameter, mask the corners with the burgundy bg so it
     * reads as a circle, then stamp two concentric gold rings around it.
     */
    private function drawCircularDarshanPhoto(
        ImageInterface $canvas,
        DailyDarshanPhoto $photo,
        array $center,
        int $radius,
    ): void {
        $diameter = $radius * 2;
        $left = $center['x'] - $radius;
        $top = $center['y'] - $radius;

        try {
            $bytes = Storage::disk('r2')->get($photo->image_path);
            if ($bytes === null || $bytes === '') {
                throw new \RuntimeException('empty photo bytes');
            }
            $img = $this->manager->decodeBinary($bytes)->cover($diameter, $diameter);
            $canvas->insert($img, $left, $top);
        } catch (\Throwable $e) {
            Log::error('DarshanShareCard: photo load failed', [
                'image_path' => $photo->image_path,
                'error' => $e->getMessage(),
            ]);
            // Fallback — a saffron-coloured disk in place of the photo.
            $canvas->drawCircle(function (CircleFactory $c) use ($center, $radius) {
                $c->at($center['x'], $center['y']);
                $c->radius($radius);
                $c->background(self::C_SAFFRON_DEEP);
            });
        }

        // Mask the four corners of the square photo by drawing burgundy
        // circles centred at each corner — only their inner quadrant
        // overlaps the photo, "erasing" everything outside the circle.
        // Same technique used for the avatar; works on both GD and
        // Imagick without a real alpha-mask compositor.
        foreach ([
            [$left, $top],                          // top-left
            [$left + $diameter, $top],              // top-right
            [$left, $top + $diameter],              // bottom-left
            [$left + $diameter, $top + $diameter],  // bottom-right
        ] as [$cx, $cy]) {
            $canvas->drawCircle(function (CircleFactory $c) use ($cx, $cy, $radius) {
                $c->at($cx, $cy);
                $c->radius($radius);
                $c->background(self::C_BURGUNDY);
            });
        }

        // Inner gold ring — sits right on the photo edge.
        $canvas->drawCircle(function (CircleFactory $c) use ($center, $radius) {
            $c->at($center['x'], $center['y']);
            $c->radius($radius - 2);
            $c->background('rgba(0,0,0,0)');
            $c->border(self::C_GOLD, 6);
        });
        // Outer gold ring — a thinner halo offset out by 22px to give the
        // framed-medallion look from the reference design.
        $canvas->drawCircle(function (CircleFactory $c) use ($center, $radius) {
            $c->at($center['x'], $center['y']);
            $c->radius($radius + 24);
            $c->background('rgba(0,0,0,0)');
            $c->border(self::C_GOLD, 3);
        });
    }

    /**
     * Render the centrepiece blessing ("જય શ્રી રામ") in gold + a small
     * gold-dash divider underneath. Kept as its own method so the
     * orchestration in render() reads top-to-bottom.
     */
    private function drawBlessing(ImageInterface $canvas, int $width, int $y, string $format): void
    {
        $cx = intval($width / 2);

        $canvas->text('જય શ્રી રામ', $cx, $y, function (FontFactory $font) use ($format) {
            $font->filename($this->gujaratiFont(bold: true));
            $font->size($format === self::FORMAT_STORY ? 120 : 88);
            $font->color(self::C_GOLD_BRIGHT);
            $font->align('center', 'center');
        });

        $divY = $y + ($format === self::FORMAT_STORY ? 95 : 65);
        $halfLen = 130;
        $canvas->drawRectangle(function (RectangleFactory $r) use ($cx, $halfLen, $divY) {
            $r->at($cx - $halfLen - 24, $divY);
            $r->size($halfLen, 2);
            $r->background(self::C_GOLD);
        });
        $canvas->drawRectangle(function (RectangleFactory $r) use ($cx, $halfLen, $divY) {
            $r->at($cx + 24, $divY);
            $r->size($halfLen, 2);
            $r->background(self::C_GOLD);
        });
        $canvas->drawCircle(function (CircleFactory $c) use ($cx, $divY) {
            $c->at($cx, $divY + 1);
            $c->radius(7);
            $c->background(self::C_GOLD);
        });
    }

    /**
     * Date • URL at the very bottom of the card, in muted cream so it
     * sits quietly under the bigger content above.
     */
    private function drawFooterMeta(ImageInterface $canvas, int $width, int $height): void
    {
        $cx = intval($width / 2);
        $metaY = $height - 70;
        $today = Carbon::now()->locale('en')->translatedFormat('d M Y');
        $canvas->text($today . '  •  patadiyahanumanji.com', $cx, $metaY, function (FontFactory $font) {
            $font->filename($this->englishFont());
            $font->size(28);
            $font->color(self::C_CREAM_BODY);
            $font->align('center', 'center');
        });
    }

    // drawFooter() removed — broken into drawBlessing + drawDevoteeBlock +
    // drawFooterMeta to give render() a clear top-to-bottom orchestration.

    /**
     * Left-aligned avatar (devotee photo OR temple-logo fallback) paired
     * with two/three lines of text on the right:
     *
     *   [ Avatar ]   {Devotee Name}                    ← only if logged in
     *                Sending Daily Blessings from
     *                Pataliya Hanumanji Temple
     *
     * For anonymous callers the name line is omitted but the avatar
     * (temple logo) + the two-line "Sending Daily Blessings..." text
     * still render so every card carries the same temple branding.
     */
    private function drawDevoteeBlock(
        ImageInterface $canvas,
        ?Devotee $devotee,
        int $width,
        int $y,
        string $format,
    ): void {
        $avatarSize = $format === self::FORMAT_STORY ? 150 : 110;
        $marginX = $format === self::FORMAT_STORY ? 70 : 50;
        $gap = 30;

        // Always render an avatar — devotee photo → temple logo fallback.
        // The fake-Devotee path in loadAvatar's logo branch needs us to
        // hand it any Devotee-or-null; null falls through to logo-only.
        $avatar = $this->loadAvatarOrLogo($devotee, $avatarSize);
        if ($avatar !== null) {
            $canvas->insert($avatar, $marginX, $y - intval($avatarSize / 2));
        }

        $textStartX = $marginX + $avatarSize + $gap;
        $textY = $y;

        // Top line: devotee name (only when logged in). Pushes the
        // "Sending Daily Blessings..." block down by one line height.
        if ($devotee !== null && ! empty($devotee->name)) {
            $name = $devotee->name;
            $nameFont = $this->pickFontForText($name, bold: true);
            $nameFontSize = $format === self::FORMAT_STORY ? 46 : 34;

            $nameY = $textY - ($format === self::FORMAT_STORY ? 50 : 35);
            $canvas->text($name, $textStartX, $nameY, function (FontFactory $font) use ($nameFont, $nameFontSize) {
                $font->filename($nameFont);
                $font->size($nameFontSize);
                $font->color(self::C_GOLD_BRIGHT);
                $font->align('left', 'center');
            });
        }

        // Two-line "Sending Daily Blessings from / Pataliya Hanumanji Temple"
        // — the constant branding the user asked to sit below the name.
        $bodyFontSize = $format === self::FORMAT_STORY ? 32 : 24;
        $lineGap = $format === self::FORMAT_STORY ? 44 : 32;
        $line1 = 'Sending Daily Blessings from';
        $line2 = 'Pataliya Hanumanji Temple';

        $canvas->text($line1, $textStartX, $textY, function (FontFactory $font) use ($bodyFontSize) {
            $font->filename($this->englishFont());
            $font->size($bodyFontSize);
            $font->color(self::C_CREAM_BODY);
            $font->align('left', 'center');
        });
        $canvas->text($line2, $textStartX, $textY + $lineGap, function (FontFactory $font) use ($bodyFontSize) {
            $font->filename($this->englishFont());
            $font->size($bodyFontSize);
            $font->color(self::C_CREAM_BODY);
            $font->align('left', 'center');
        });
    }

    /**
     * Avatar loader for the devotee block. Always returns something
     * (either the devotee's photo or the temple logo), so the new
     * left-aligned layout never has an empty avatar slot. Null
     * only when even the logo can't load — operations sees the
     * Log::warning in that case.
     */
    private function loadAvatarOrLogo(?Devotee $devotee, int $size): ?ImageInterface
    {
        if ($devotee !== null) {
            $photo = $this->loadDevoteePhoto($devotee, $size);
            if ($photo !== null) {
                $this->maskCorners($photo, $size);
                $this->stampGoldRing($photo, $size);
                return $photo;
            }
        }

        $logo = $this->loadTempleLogo($size);
        if ($logo === null) {
            Log::warning('DarshanShareCard: temple-logo avatar fallback failed', [
                'devotee_id' => $devotee?->getKey(),
            ]);
            return null;
        }
        $this->maskCorners($logo, $size);
        $this->stampGoldRing($logo, $size);
        return $logo;
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
     * the card-body colour. Cheap, works with both Imagick and GD.
     * Visually indistinguishable from a real alpha mask at ≤ 150px.
     *
     * Geometry: for each corner, an opaque triangular wedge is faked by
     * a quarter-circle drawn at the inverted corner with a radius equal
     * to half the avatar size. drawCircle fills the entire ellipse so we
     * stamp it OUTSIDE the avatar bounds — only the inner quadrant
     * overlaps the avatar. Bg colour matches C_BURGUNDY (the redesigned
     * canvas) so the masked-out corners blend invisibly into the card.
     */
    private function maskCorners(ImageInterface $avatar, int $size): void
    {
        $r = intval($size / 2);
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
                $c->background(self::C_BURGUNDY);
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
        $hash = substr(sha1("{$photo->id}|{$photo->updated_at?->timestamp}|{$devoteeSegment}|{$format}|v6"), 0, 12);

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
