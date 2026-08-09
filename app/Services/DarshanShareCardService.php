<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyDarshanPhoto;
use App\Models\Devotee;
use App\Models\SystemSetting;
use App\Support\ShapedText;
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

        $text = $this->cardText();

        // 2. Saffron top band (trust branding).
        $headerHeight = $format === self::FORMAT_STORY ? 180 : 130;
        $this->drawHeaderBand($canvas, $width, $headerHeight);
        $this->drawHeaderText($canvas, $width, $headerHeight, $text['trust']);

        // 3. Darshan photo — 4:5 vertical rectangle (captures the full
        //    sanctum, both murtis included — the old circle cropped the
        //    lower murti out), borderless, its bottom third fading into
        //    the burgundy so photo and card read as one surface.
        $photoW = $format === self::FORMAT_STORY ? 840 : 420;
        $photoH = intval($photoW * 5 / 4);
        $photoLeft = intval(($width - $photoW) / 2);
        $photoTop = $headerHeight + ($format === self::FORMAT_STORY ? 44 : 34);
        $this->drawDarshanPhotoRect($canvas, $photo, $photoLeft, $photoTop, $photoW, $photoH);
        $photoBottom = $photoTop + $photoH;

        // 4. Blessing — dominant centred element, localized.
        $blessingY = $photoBottom + ($format === self::FORMAT_STORY ? 86 : 61);
        $this->drawBlessing($canvas, $width, $blessingY, $format, $text['blessing']);

        // 5. Devotee block — avatar + name + localized tagline, centred.
        $rowY = $blessingY + ($format === self::FORMAT_STORY ? 266 : 150);
        $this->drawDevoteeBlock($canvas, $devotee, $width, $rowY, $format, $text);

        // 6. Footer meta — pulled close to the row so no empty burgundy
        //    gap shows below the avatar.
        $footerY = $height - ($format === self::FORMAT_STORY ? 100 : 65);
        $this->drawFooterMeta($canvas, $photo, $width, $footerY);

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

        // (The ૐ glyph that used to anchor the left edge is removed per
        // design feedback — the band carries only the trust name now,
        // truly centred since there's no glyph to offset around.)
        $headerFont = $this->pickFontForText($trustName, bold: true);
        $this->drawTextShaped($canvas, $trustName, intval($width / 2), $centerY, 40, self::C_WHITE, 'center', 'center',
            fn () => $canvas->text($trustName, intval($width / 2), $centerY, function (FontFactory $font) use ($headerFont) {
                $font->filename($headerFont);
                $font->size(40);
                $font->color(self::C_WHITE);
                $font->align('center', 'center');
                $font->lineHeight(1.2);
            }), $this->pangoSerifFamilyFor($trustName));
    }

    /**
     * All card copy, localized to the requesting devotee's app language
     * (SetApiLocale applies X-Locale before the controller runs). Every
     * string that lands on the card comes from here — no hardcoded
     * English left in the drawing methods.
     */
    private function cardText(): array
    {
        return match (app()->getLocale()) {
            'hi' => [
                'blessing' => '॥ जय सियाराम ॥',
                'line1' => 'पातालिया हनुमानजी मंदिर की ओर से',
                'line2' => 'दैनिक दर्शन आशीर्वाद',
                'trust' => 'श्री पातालिया हनुमानजी सेवा ट्रस्ट',
            ],
            'en' => [
                'blessing' => '॥ Jay Siya Ram ॥',
                'line1' => 'Sending Daily Blessings from',
                'line2' => 'Patadiya Hanumanji Temple',
                'trust' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            ],
            default => [
                'blessing' => '॥ જય સિયારામ ॥',
                'line1' => 'પાતાળિયા હનુમાનજી મંદિર તરફથી',
                'line2' => 'દૈનિક દર્શન આશીર્વાદ',
                'trust' => 'શ્રી પાતાળિયા હનુમાનજી સેવા ટ્રસ્ટ',
            ],
        };
    }

    /**
     * Pick a font FILE whose glyph coverage matches the script in $text,
     * in the same families the Flutter app renders with (AppFonts.serif:
     * Noto Serif Gujarati / Noto Serif Devanagari / Marcellus). Used by
     * the Intervention fallback path; the pango path picks the matching
     * fontconfig family via [pangoSerifFamilyFor]/[pangoSansFamilyFor].
     */
    private function pickFontForText(string $text, bool $bold): string
    {
        $sample = $this->scriptSample($text);

        if (preg_match('/[\x{0A80}-\x{0AFF}]/u', $sample)) {
            return resource_path('fonts/NotoSerifGujarati-SemiBold.ttf');
        }
        if (preg_match('/[\x{0900}-\x{097F}]/u', $sample)) {
            return resource_path('fonts/NotoSerifDevanagari-SemiBold.ttf');
        }

        return resource_path('fonts/Marcellus-Regular.ttf');
    }

    /**
     * Fontconfig serif family for pango, matched to $text's script.
     *
     * Unlike [pickFontForText] this does NOT discount the danda: a romanised
     * line wrapped in ॥ still has to reach pango (Marcellus has no danda
     * glyph), and there Noto Serif Devanagari draws the danda while
     * fontconfig falls back for the Latin run.
     */
    private function pangoSerifFamilyFor(string $text): ?string
    {
        if (preg_match('/[\x{0A80}-\x{0AFF}]/u', $text)) {
            return 'Noto Serif Gujarati';
        }
        if (preg_match('/[\x{0900}-\x{097F}]/u', $text)) {
            return 'Noto Serif Devanagari';
        }

        return null; // pure Latin never routes through pango
    }

    /**
     * Strip script-neutral danda punctuation (। U+0964, ॥ U+0965) before
     * detecting a run's script. Both live in the Devanagari Unicode block
     * but frame Gujarati and romanised text alike — '॥ Jay Siya Ram ॥'
     * would otherwise be typed as Devanagari and, on the pango-less GD
     * fallback path, drawn with the bundled NotoSerifDevanagari subset,
     * which carries no Latin glyphs at all (every letter tofu). Losing the
     * two dandas there is the far smaller failure.
     */
    private function scriptSample(string $text): string
    {
        return preg_replace('/[\x{0964}\x{0965}]/u', '', $text) ?? $text;
    }

    /**
     * Cover-crop the darshan photo to a 4:5 vertical rectangle and place
     * it borderless on the canvas. The bottom ~30% receives a burgundy
     * gradient (transparent → opaque card colour) so the photo dissolves
     * into the background instead of ending on a hard edge. Replaces the
     * circular crop, which couldn't hold both murtis in frame.
     */
    private function drawDarshanPhotoRect(
        ImageInterface $canvas,
        DailyDarshanPhoto $photo,
        int $left,
        int $top,
        int $w,
        int $h,
    ): void {
        try {
            $bytes = Storage::disk('r2')->get($photo->image_path);
            if ($bytes === null || $bytes === '') {
                throw new \RuntimeException('empty photo bytes');
            }
            $img = $this->manager->decodeBinary($bytes)->cover($w, $h);
            $canvas->insert($img, $left, $top);
        } catch (\Throwable $e) {
            Log::error('DarshanShareCard: photo load failed', [
                'image_path' => $photo->image_path,
                'error' => $e->getMessage(),
            ]);
            // Fallback — a saffron field where the photo would sit.
            $canvas->drawRectangle(function (RectangleFactory $r) use ($left, $top, $w, $h) {
                $r->at($left, $top);
                $r->size($w, $h);
                $r->background(self::C_SAFFRON_DEEP);
            });
        }

        $this->drawBottomFade($canvas, $left, $w, $top + $h, intval($h * 0.30));
    }

    /**
     * Stacked 4px strips of increasingly opaque burgundy over the
     * photo's bottom edge — a gradient both GD and Imagick can draw
     * through Intervention's rgba() rectangle fills. The ease-in
     * exponent keeps the upper strips near-invisible so the blend
     * starts subtle and lands fully opaque exactly at the photo edge.
     */
    private function drawBottomFade(ImageInterface $canvas, int $left, int $width, int $bottom, int $fadeHeight): void
    {
        [$r, $g, $b] = sscanf(self::C_BURGUNDY, '#%02x%02x%02x');
        $step = 4;
        $steps = max(1, intdiv($fadeHeight, $step));
        for ($i = 0; $i < $steps; $i++) {
            $alpha = (($i + 1) / $steps) ** 1.6;
            $y = $bottom - $fadeHeight + $i * $step;
            $canvas->drawRectangle(function (RectangleFactory $rect) use ($left, $width, $y, $step, $r, $g, $b, $alpha) {
                $rect->at($left, $y);
                $rect->size($width, $step);
                // Intervention v4's rgba() parser rejects trailing-zero
                // alphas ("1.000" → InvalidArgumentException; Sentry
                // 2026-08-05) — %.3f always emits them, so the final,
                // fully-opaque strip threw on every render. (string)round()
                // yields "1" / "0.348" which the parser accepts.
                $rect->background(sprintf('rgba(%d,%d,%d,%s)', $r, $g, $b, (string) round($alpha, 3)));
            });
        }
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
     * Render the centrepiece blessing ("॥ જય સિયારામ ॥") in gold + a small
     * gold-dash divider underneath. Kept as its own method so the
     * orchestration in render() reads top-to-bottom.
     */
    /**
     * Renders the three-element blessing cluster:
     *
     *   ॥ જય સિયારામ ॥    ← gold, large
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
        ?string $pangoFamily = null,
    ): void {
        if (ShapedText::needsShaping($text) && ShapedText::available()) {
            $gd = ShapedText::render($text, $sizePx, $hexColor, null, $pangoFamily);
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
                $canvas->insert($bytes, max(0, $left), max(0, $top));

                return;
            }
        }

        $fallback();
    }

    private function drawBlessing(ImageInterface $canvas, int $width, int $y, string $format, string $blessing): void
    {
        $cx = intval($width / 2);

        // Blessing line ('॥ જય સિયારામ ॥' / '॥ जय सियाराम ॥' / '॥ Jay Siya Ram ॥')
        // — dominant centred element, serif per the app's typography.
        $blessSize = $format === self::FORMAT_STORY ? 100 : 72;
        $blessFont = $this->pickFontForText($blessing, bold: true);
        $this->drawTextShaped($canvas, $blessing, $cx, $y, $blessSize, self::C_GOLD_BRIGHT, 'center', 'center',
            fn () => $canvas->text($blessing, $cx, $y, function (FontFactory $font) use ($blessSize, $blessFont) {
                $font->filename($blessFont);
                $font->size($blessSize);
                $font->color(self::C_GOLD_BRIGHT);
                $font->align('center', 'center');
            }), $this->pangoSerifFamilyFor($blessing));

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
    private function drawFooterMeta(ImageInterface $canvas, DailyDarshanPhoto $photo, int $width, int $metaY): void
    {
        $cx = intval($width / 2);
        // The photo's own darshan date, not now() — the latest photo can
        // be yesterday's if an upload was missed, and the card must not
        // claim a darshan that didn't happen. Numeric d/m/Y (trust
        // standard) keeps the footer locale-neutral, so the Latin sans
        // font below never has to shape Indic month names.
        $date = ($photo->captured_on ?? now())->format('d/m/Y');
        // Separator switched from bullet to pipe per the new mockup.
        $canvas->text($date.'   |   patadiyahanumanji.com', $cx, $metaY, function (FontFactory $font) {
            $font->filename($this->sansFont());
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
     *                         Patadiya Hanumanji Temple
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
        array $text,
    ): void {
        $avatarSize = $format === self::FORMAT_STORY ? 260 : 150;
        $nameSize = $format === self::FORMAT_STORY ? 68 : 48;
        $bodySize = $format === self::FORMAT_STORY ? 40 : 28;
        $gap = $format === self::FORMAT_STORY ? 50 : 36;
        // Width estimate for the longest body line — used to centre the
        // whole avatar+text composition on the canvas. Localized lines
        // land in the same ballpark (the Gujarati/Hindi taglines were
        // written to similar lengths as the English ones).
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
        $line1 = (string) $text['line1'];
        $line2 = (string) $text['line2'];

        $drawBodyLine = function (string $line, int $lineY) use ($canvas, $textStartX, $bodySize): void {
            // Body copy is sans, like the app (Hind Vadodara / Hind).
            // Indic lines shape through pango's sans families; Latin
            // renders straight from the bundled Hind Vadodara TTF.
            $this->drawTextShaped($canvas, $line, $textStartX, $lineY, $bodySize, self::C_CREAM_BODY, 'left', 'center',
                fn () => $canvas->text($line, $textStartX, $lineY, function (FontFactory $font) use ($bodySize) {
                    $font->filename($this->sansFont());
                    $font->size($bodySize);
                    $font->color(self::C_CREAM_BODY);
                    $font->align('left', 'center');
                }));
        };

        if ($hasName) {
            $name = (string) $devotee->name;
            $nameFont = $this->pickFontForText($name, bold: true);

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
                }), $this->pangoSerifFamilyFor($name));
            $drawBodyLine($line1, $body1Y);
            $drawBodyLine($line2, $body2Y);

            return;
        }

        // Anonymous — just two tagline lines, centred against the avatar.
        $bodyLineGap = $format === self::FORMAT_STORY ? 66 : 48;
        $drawBodyLine($line1, $y - intval($bodyLineGap / 2));
        $drawBodyLine($line2, $y + intval($bodyLineGap / 2));
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
        // v19: LC_ALL fix — v18 cards rendered via FPM had unshaped Indic
        // text (pango failed in the C locale) and are cached for 30 days.
        // v20: Pataliya → Patadiya footer spelling fix.
        // v21: 4:5 rect photo + bottom fade, no ૐ, localized copy, app fonts.
        // Locale is part of the identity now — the card's text follows
        // X-Locale, so each language renders its own file.
        // v22: footer date = photo captured_on (was now()) in d/m/Y.
        // v23: blessing standardised to '॥ જય સિયારામ ॥' / '॥ जय सियाराम ॥' /
        //      '॥ Jay Siya Ram ॥' (was 'જય શ્રી રામ' etc.).
        $locale = app()->getLocale();
        $hash = substr(sha1("{$photo->id}|{$photo->updated_at?->timestamp}|{$devoteeSegment}|{$format}|{$locale}|v23"), 0, 12);

        return self::STORAGE_PREFIX."/{$date}/{$devoteeSegment}-{$format}-{$locale}-{$hash}.jpg";
    }

    /** App-matching sans (Hind Vadodara) for body copy, meta, Latin lines. */
    private function sansFont(): string
    {
        return resource_path('fonts/HindVadodara-Regular.ttf');
    }
}
