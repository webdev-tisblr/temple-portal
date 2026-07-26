<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyDarshanPhoto;
use App\Models\Devotee;
use App\Models\SystemSetting;
use App\Support\ShapedText;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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
                $this->manager = new ImageManager(new ImagickDriver);

                return;
            } catch (\Throwable $e) {
                Log::warning('DarshanShareCard: Imagick driver init failed — falling back to GD', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $this->manager = new ImageManager(new GdDriver);
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

        // Fastest path: we already confirmed this exact card exists on a
        // recent request. The storage path is deterministic (photo id +
        // updated_at + devotee + format + version), so a cached URL can't go
        // stale for the wrong image — return it without any R2 round-trip.
        $cacheKey = 'darshan_card_url:'.$storagePath;
        $cachedUrl = Cache::get($cacheKey);
        if (is_string($cachedUrl) && $cachedUrl !== '') {
            return [
                'url' => $cachedUrl,
                'format' => $format,
                'width' => $width,
                'height' => $height,
                'cached' => true,
            ];
        }

        // Idempotent: skip the render when we already have this card. The
        // ->exists() is an R2 HTTP call, so memoise the resolved URL below.
        if ($disk->exists($storagePath)) {
            $url = $disk->url($storagePath);
            Cache::put($cacheKey, $url, now()->addHours(12));

            return [
                'url' => $url,
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

        $url = $disk->url($storagePath);
        Cache::put($cacheKey, $url, now()->addHours(12));

        return [
            'url' => $url,
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

        // 4. 'જય શ્રી રામ' blessing — dominant centred element.
        $blessingY = $photoCenter['y'] + $photoRadius
            + ($format === self::FORMAT_STORY ? 110 : 75);
        $this->drawBlessing($canvas, $width, $blessingY, $format);

        // 5. Devotee block — big side-by-side, whole composition centred
        //    horizontally. Offset chosen so the avatar has roughly equal
        //    breathing room above (to the divider) and below (to the
        //    footer meta). Story math: divider at blessing+88, footer at
        //    height-100, avatar 280px tall → centerline at blessing+349
        //    puts a ~120px margin both sides.
        $rowY = $blessingY + ($format === self::FORMAT_STORY ? 349 : 215);
        $this->drawDevoteeBlock($canvas, $devotee, $width, $rowY, $format);

        // 6. Footer meta — pulled close to the row so no empty burgundy
        //    gap shows below the avatar.
        $footerY = $height - ($format === self::FORMAT_STORY ? 100 : 65);
        $this->drawFooterMeta($canvas, $width, $footerY);

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
        $this->drawTextShaped($canvas, $trustName, intval($width / 2) + 30, $centerY, 40, self::C_WHITE, 'center', 'center',
            fn () => $canvas->text($trustName, intval($width / 2) + 30, $centerY, function (FontFactory $font) use ($headerFont) {
                $font->filename($headerFont);
                $font->size(40);
                $font->color(self::C_WHITE);
                $font->align('center', 'center');
                $font->lineHeight(1.2);
            }));
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
     * Cover-crop the darshan photo to a square the size of the photo
     * circle's diameter, alpha-mask it into a clean circle (Imagick only),
     * insert onto the canvas, and stamp two concentric gold rings.
     *
     * Earlier implementation tried to mask corners by drawing four
     * burgundy circles AFTER inserting the square photo. The geometry
     * produced a 4-pointed star (not a circle) AND the masking circles
     * bled outside the photo bounds, eating into the saffron header.
     * The Imagick path now masks BEFORE insert — no bleed possible,
     * crisp circular crop. GD falls back to a square photo because GD's
     * alpha-mask compositing isn't reachable through Intervention v4.
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

            $this->applyCircularMask($img, $diameter);

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

        // Single thin gold ring on the edge — the concentric outer halo
        // is dropped in this iteration to match the minimal mockup.
        $canvas->drawCircle(function (CircleFactory $c) use ($center, $radius) {
            $c->at($center['x'], $center['y']);
            $c->radius($radius);
            $c->background('rgba(0,0,0,0)');
            $c->border(self::C_GOLD, 4);
        });
    }

    /**
     * Apply a circular alpha mask to a square image. Uses raw Imagick
     * composite when available (clean, anti-aliased circular crop). On
     * GD the operation is a no-op — Intervention v4 doesn't expose
     * GD's alpha-mask compositing, and approximating it with
     * drawCircle corners produces a 4-pointed star artefact (the bug
     * this method exists to fix). On GD the photo stays square and
     * the gold rings around it visually frame it; not as polished as
     * the Imagick path but legible.
     */
    private function applyCircularMask(ImageInterface $img, int $size): void
    {
        try {
            $native = $img->core()->native();
        } catch (\Throwable $e) {
            return;
        }

        if (! ($native instanceof \Imagick) || ! class_exists(\ImagickDraw::class)) {
            return; // GD or no Imagick — leave the photo square.
        }

        $radius = intval($size / 2);

        // Build a circular-mask image: transparent canvas with a white
        // filled circle. The white pixels keep alpha; everything else
        // becomes transparent in the destination via COMPOSITE_DSTIN.
        $mask = new \Imagick;
        $mask->newImage($size, $size, new \ImagickPixel('transparent'));
        $mask->setImageFormat('png');

        $draw = new \ImagickDraw;
        $draw->setFillColor('white');
        // ImagickDraw::circle(centerX, centerY, edgeX, edgeY).
        $draw->circle($radius, $radius, $size, $radius);
        $mask->drawImage($draw);

        // Photo native may not have an alpha channel yet (JPEGs don't).
        // setImageMatte ensures the channel exists before composite.
        if (method_exists($native, 'setImageMatte')) {
            $native->setImageMatte(true);
        }
        $native->compositeImage($mask, \Imagick::COMPOSITE_DSTIN, 0, 0);
        $native->setImageFormat('png'); // preserve alpha when re-encoding for insert
        $mask->clear();
    }

    /**
     * Render the centrepiece blessing ("જય શ્રી રામ") in gold + a small
     * gold-dash divider underneath. Kept as its own method so the
     * orchestration in render() reads top-to-bottom.
     */
    /**
     * Renders the three-element blessing cluster:
     *
     *   જય શ્રી રામ       ← gold, large
     *   ──── ● ────       ← thin gold divider
     *   પ્રાર્થના સહિત   ← cream, small, Gujarati for 'With prayers'
     *
     * Vertical positions are anchored to the supplied $y (the blessing
     * baseline); divider and sub-line cascade downward from there.
     */

    /**
     * Draw text with correct Indic shaping when possible.
     *
     * ImageMagick on this host lacks the raqm delegate, so Imagick's
     * annotate is as shapeless as GD for Gujarati/Devanagari (the
     * driver-selection comment above predates this discovery — શ્રી
     * rendered with a detached ્ર on real cards, 2026-07-26). Indic
     * strings render through pango (ShapedText) into a transparent PNG
     * placed at the equivalent aligned position; everything else uses
     * the normal Intervention text path via $fallback.
     */
    private function drawTextShaped(
        ImageInterface $canvas,
        string $text,
        int $x,
        int $y,
        float $sizePx,
        string $hexColor,
        string $halign,
        string $valign,
        callable $fallback,
    ): void {
        if (ShapedText::needsShaping($text) && ShapedText::available()) {
            $gd = ShapedText::render($text, $sizePx, $hexColor);
            if ($gd instanceof \GdImage) {
                $w = imagesx($gd);
                $h = imagesy($gd);
                $left = match ($halign) {
                    'center' => $x - intdiv($w, 2), 'right' => $x - $w, default => $x
                };
                $top = match ($valign) {
                    'center' => $y - intdiv($h, 2), 'bottom' => $y - $h, default => $y
                };
                ob_start();
                imagepng($gd);
                $bytes = (string) ob_get_clean();
                imagedestroy($gd);
                $canvas->place($bytes, 'top-left', max(0, $left), max(0, $top));

                return;
            }
        }

        $fallback();
    }

    private function drawBlessing(ImageInterface $canvas, int $width, int $y, string $format): void
    {
        $cx = intval($width / 2);

        // 'જય શ્રી રામ' — dominant centred element.
        $blessSize = $format === self::FORMAT_STORY ? 100 : 72;
        $this->drawTextShaped($canvas, 'જય શ્રી રામ', $cx, $y, $blessSize, self::C_GOLD_BRIGHT, 'center', 'center',
            fn () => $canvas->text('જય શ્રી રામ', $cx, $y, function (FontFactory $font) use ($blessSize) {
                $font->filename($this->gujaratiFont(bold: true));
                $font->size($blessSize);
                $font->color(self::C_GOLD_BRIGHT);
                $font->align('center', 'center');
            }));

        // Gold divider underneath — short dashes either side of a centred
        // dot. Earlier 2px stroke was too thin to read at full size; now
        // 5px tall + 160px wide per side + 10px centre dot.
        $divY = $y + ($format === self::FORMAT_STORY ? 88 : 62);
        $halfLen = $format === self::FORMAT_STORY ? 160 : 110;
        $thickness = $format === self::FORMAT_STORY ? 5 : 4;
        $dotR = $format === self::FORMAT_STORY ? 10 : 7;
        $gapToDot = $format === self::FORMAT_STORY ? 28 : 20;

        $canvas->drawRectangle(function (RectangleFactory $r) use ($cx, $halfLen, $divY, $thickness, $gapToDot) {
            $r->at($cx - $halfLen - $gapToDot, $divY - intval($thickness / 2));
            $r->size($halfLen, $thickness);
            $r->background(self::C_GOLD);
        });
        $canvas->drawRectangle(function (RectangleFactory $r) use ($cx, $halfLen, $divY, $thickness, $gapToDot) {
            $r->at($cx + $gapToDot, $divY - intval($thickness / 2));
            $r->size($halfLen, $thickness);
            $r->background(self::C_GOLD);
        });
        $canvas->drawCircle(function (CircleFactory $c) use ($cx, $divY, $dotR) {
            $c->at($cx, $divY);
            $c->radius($dotR);
            $c->background(self::C_GOLD);
        });
    }

    /**
     * Date • URL at the bottom of the card, in muted cream so it sits
     * quietly under the bigger content above. Y is passed in by the
     * caller (was hardcoded at $height-70 before) so the layout can
     * lift it closer to the devotee block and absorb the empty
     * burgundy gap the user flagged.
     */
    private function drawFooterMeta(ImageInterface $canvas, int $width, int $metaY): void
    {
        $cx = intval($width / 2);
        $today = Carbon::now()->locale('en')->translatedFormat('d M Y');
        // Separator switched from bullet to pipe per the new mockup.
        $canvas->text($today.'   |   patadiyahanumanji.com', $cx, $metaY, function (FontFactory $font) {
            $font->filename($this->englishFont());
            $font->size(26);
            $font->color(self::C_CREAM_BODY);
            $font->align('center', 'center');
        });
    }

    // drawFooter() removed — broken into drawBlessing + drawDevoteeBlock +
    // drawFooterMeta to give render() a clear top-to-bottom orchestration.

    /**
     * Big side-by-side devotee block, the whole composition centred
     * horizontally on the canvas:
     *
     *        [ Big Avatar ]   Name                       ← bold gold
     *                         Sending Daily Blessings from
     *                         Pataliya Hanumanji Temple
     *
     * Anonymous callers omit the name; the two-line tagline is
     * vertically centred against the avatar.
     *
     * $y is the centerline of the block (avatar vertical centre and
     * the midpoint of the text stack).
     */
    private function drawDevoteeBlock(
        ImageInterface $canvas,
        ?Devotee $devotee,
        int $width,
        int $y,
        string $format,
    ): void {
        // Bigger again per user feedback — height growing is fine.
        $avatarSize = $format === self::FORMAT_STORY ? 280 : 200;
        $nameSize = $format === self::FORMAT_STORY ? 72 : 52;
        $bodySize = $format === self::FORMAT_STORY ? 42 : 30;
        $gap = $format === self::FORMAT_STORY ? 50 : 36;
        // Width estimate for the longest body line — used to centre the
        // whole avatar+text composition on the canvas. The tagline is
        // a fixed English string so the estimate is stable enough; if
        // a future caller localises the lines, swap to a measured
        // bounding box via Imagick's queryFontMetrics.
        $textWidthEstimate = $format === self::FORMAT_STORY ? 620 : 450;

        $blockWidth = $avatarSize + $gap + $textWidthEstimate;
        $blockLeftX = intval(($width - $blockWidth) / 2);

        $hasName = $devotee !== null && ! empty($devotee->name);

        // Avatar — positioned so its vertical centre is at $y.
        $avatar = $this->loadAvatarOrLogo($devotee, $avatarSize);
        if ($avatar !== null) {
            $canvas->insert($avatar, $blockLeftX, $y - intval($avatarSize / 2));
        }

        $textStartX = $blockLeftX + $avatarSize + $gap;
        $line1 = 'Sending Daily Blessings from';
        $line2 = 'Pataliya Hanumanji Temple';

        if ($hasName) {
            $name = (string) $devotee->name;
            $nameFont = $this->pickFontForText($name, bold: true);

            // 3 stacked text lines centred vertically against the (much
            // bigger) avatar. Gaps scale with the name + body sizes.
            $nameToBody = $format === self::FORMAT_STORY ? 80 : 58;
            $bodyLineGap = $format === self::FORMAT_STORY ? 62 : 44;

            $nameY = $y - $nameToBody;
            $body1Y = $y + intval($bodyLineGap / 4);
            $body2Y = $body1Y + $bodyLineGap;

            $this->drawTextShaped($canvas, $name, $textStartX, $nameY, $nameSize, self::C_GOLD_BRIGHT, 'left', 'center',
                fn () => $canvas->text($name, $textStartX, $nameY, function (FontFactory $font) use ($nameFont, $nameSize) {
                    $font->filename($nameFont);
                    $font->size($nameSize);
                    $font->color(self::C_GOLD_BRIGHT);
                    $font->align('left', 'center');
                }));
            $canvas->text($line1, $textStartX, $body1Y, function (FontFactory $font) use ($bodySize) {
                $font->filename($this->englishFont());
                $font->size($bodySize);
                $font->color(self::C_CREAM_BODY);
                $font->align('left', 'center');
            });
            $canvas->text($line2, $textStartX, $body2Y, function (FontFactory $font) use ($bodySize) {
                $font->filename($this->englishFont());
                $font->size($bodySize);
                $font->color(self::C_CREAM_BODY);
                $font->align('left', 'center');
            });

            return;
        }

        // Anonymous — just two tagline lines, centred against the avatar.
        $bodyLineGap = $format === self::FORMAT_STORY ? 66 : 48;
        $line1Y = $y - intval($bodyLineGap / 2);
        $line2Y = $y + intval($bodyLineGap / 2);

        $canvas->text($line1, $textStartX, $line1Y, function (FontFactory $font) use ($bodySize) {
            $font->filename($this->englishFont());
            $font->size($bodySize);
            $font->color(self::C_CREAM_BODY);
            $font->align('left', 'center');
        });
        $canvas->text($line2, $textStartX, $line2Y, function (FontFactory $font) use ($bodySize) {
            $font->filename($this->englishFont());
            $font->size($bodySize);
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
                $this->applyCircularMask($photo, $size);
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
        $this->applyCircularMask($logo, $size);
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
        $devoteeSegment = $devotee ? 'd'.$devotee->getKey() : 'anon';
        $date = $photo->captured_on?->format('Y-m-d') ?? now()->format('Y-m-d');

        // Hash includes the photo's updated_at so re-uploading the source
        // image invalidates the cached card automatically. The trailing
        // version suffix is bumped manually whenever the rendering pipeline
        // produces materially different output (driver swap, layout shift,
        // typography change) — v2 invalidates the pre-Imagick-fallback
        // cards that had tofu boxes for the trust-name header.
        $hash = substr(sha1("{$photo->id}|{$photo->updated_at?->timestamp}|{$devoteeSegment}|{$format}|v17"), 0, 12);

        return self::STORAGE_PREFIX."/{$date}/{$devoteeSegment}-{$format}-{$hash}.jpg";
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
