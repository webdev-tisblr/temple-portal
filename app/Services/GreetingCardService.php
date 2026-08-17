<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyDarshanPhoto;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Support\DevoteeLocale;
use App\Support\ScriptFont;
use App\Support\ShapedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GreetingCardService
{
    /**
     * Fallback overlay bytes already fetched in this process, keyed by the
     * card's date. @see fallbackOverlayBytes
     *
     * @var array<string, string|null>
     */
    private array $fallbackBytesMemo = [];

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

    /**
     * Generate a greeting card image for a seva booking (when the seva
     * has a card template configured). Same pipeline as donations —
     * only the template owner, variables and output path differ.
     */
    public function generateForSevaBooking(SevaBooking $booking): ?string
    {
        if (! function_exists('imagecreatefrompng')) {
            Log::warning('GD extension not available, skipping seva greeting card generation');

            return null;
        }

        try {
            return $this->generateSevaCard($booking);
        } catch (\Throwable $e) {
            Log::error('Seva greeting card generation failed', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    private function generateCard(Donation $donation): ?string
    {
        $donation->loadMissing('donationType', 'devotee', 'campaign');

        // Where the artwork lives, in priority order.
        //
        // A CAMPAIGN donation carries no donation_type_id at all — the donate
        // form hides the type picker in campaign mode and offers sub-causes
        // instead — so before 2026-08-13 it fell straight out of this method
        // and no campaign gift ever produced a card. The campaign now carries
        // its own artwork, exactly as sevas and donation types do.
        //
        // Type-level artwork still wins where both exist, so a campaign
        // donation that somehow does carry a type behaves as it always has.
        $source = $this->cardSourceFor($donation);

        if ($source === null) {
            return null;
        }

        $locale = $this->localeForDevotee($donation->devotee);
        $isCampaignCard = $source instanceof DonationCampaign;

        $pngBytes = $this->composeCard(
            $this->templateForLocale($source, $locale),
            $source->greeting_card_config['overlays'] ?? [],
            fn (string $fieldKey): ?string => $isCampaignCard
                ? $this->resolveCampaignFieldValue($fieldKey, $donation, $locale)
                : $this->resolveFieldValue($fieldKey, $donation, $locale),
            // The day the gift was made — so an empty photo slot carries THAT
            // day's darshan, and still does when the card is regenerated later.
            $donation->created_at,
        );
        if ($pngBytes === null) {
            return null;
        }

        $outputPath = $this->pathForDonation($donation, $locale);
        Storage::disk('r2_private')->put($outputPath, $pngBytes);
        $donation->update(['greeting_card_path' => $outputPath]);

        return $outputPath;
    }

    private function generateSevaCard(SevaBooking $booking): ?string
    {
        $booking->loadMissing('seva', 'devotee');
        $seva = $booking->seva;

        if (! $seva || ! $seva->greeting_card_template || ! $seva->greeting_card_config) {
            return null;
        }

        $locale = $this->localeForDevotee($booking->devotee);

        // slot_time_label translates via __() — render under the devotee's
        // locale so "Full Day" comes out in their language, then restore.
        $previousLocale = app()->getLocale();
        app()->setLocale($locale);
        try {
            $pngBytes = $this->composeCard(
                $this->templateForLocale($seva, $locale),
                $seva->greeting_card_config['overlays'] ?? [],
                fn (string $fieldKey): ?string => $this->resolveSevaFieldValue($fieldKey, $booking, $locale),
                // The seva DAY, not the booking day: the card is the keepsake
                // of the seva being performed, so it carries that morning's
                // darshan even when it was booked a month earlier.
                $booking->booking_date ?? $booking->created_at,
            );
        } finally {
            app()->setLocale($previousLocale);
        }
        if ($pngBytes === null) {
            return null;
        }

        $outputPath = $this->pathForSevaBooking($booking, $locale);
        Storage::disk('r2_private')->put($outputPath, $pngBytes);
        $booking->update(['greeting_card_path' => $outputPath]);

        return $outputPath;
    }

    /**
     * Storage keys for greeting cards, locale-suffixed.
     *
     * The `-{locale}` suffix is load-bearing for the same reason it is on
     * seva receipts and hall/store invoices: r2_private is a regenerable
     * cache, and the download endpoints self-heal by comparing the STORED
     * path against the one these methods produce for the devotee's CURRENT
     * language. Before 2026-08-12 the card was keyed on the id alone, so a
     * devotee who switched language kept being served the card rendered in
     * the old one until the sweep cleared it — and there was no locale in
     * the path to even detect the mismatch from.
     *
     * Both templates and overlay text differ per language
     * (greeting_card_template_hi/_en), so these really are distinct images.
     */
    public function pathForDonation(Donation $donation, string $locale): string
    {
        return 'greeting-cards/'.$donation->id.'-'.$locale.'.png';
    }

    public function pathForSevaBooking(SevaBooking $booking, string $locale): string
    {
        return 'greeting-cards/seva/'.$booking->id.'-'.$locale.'.png';
    }

    /** Missing card, or one rendered in a language the devotee no longer uses. */
    public function needsRegeneration(Donation $donation): bool
    {
        if (! $donation->greeting_card_path) {
            return true;
        }

        return $donation->greeting_card_path !== $this->pathForDonation(
            $donation,
            $this->localeForDevotee($donation->loadMissing('devotee')->devotee),
        );
    }

    /** Seva twin of needsRegeneration(). */
    public function sevaCardNeedsRegeneration(SevaBooking $booking): bool
    {
        if (! $booking->greeting_card_path) {
            return true;
        }

        return $booking->greeting_card_path !== $this->pathForSevaBooking(
            $booking,
            $this->localeForDevotee($booking->loadMissing('devotee')->devotee),
        );
    }

    /**
     * Devotee's preferred render language, defaulting to Gujarati.
     *
     * The implementation moved to App\Support\DevoteeLocale so the receipt
     * and invoice services share ONE copy of this rule (2026-08-09). Kept as
     * a thin wrapper because it is used as a closure target below.
     */
    private function localeForDevotee(?Model $devotee): string
    {
        return DevoteeLocale::for($devotee);
    }

    /**
     * Pick the background template for a locale. The bare column is the
     * Gujarati/default image; hi/en variants fall back to it when the
     * admin hasn't uploaded one. All variants share ONE overlay layout,
     * so locale images must have the same dimensions.
     */
    private function templateForLocale(Model $owner, string $locale): ?string
    {
        $path = match ($locale) {
            'hi' => $owner->getAttribute('greeting_card_template_hi'),
            'en' => $owner->getAttribute('greeting_card_template_en'),
            default => null,
        };

        return $path ?: $owner->getAttribute('greeting_card_template');
    }

    /**
     * Shared compositing pipeline: fetch the template from R2 public,
     * apply every overlay through the caller's field resolver, return
     * the finished PNG bytes (null on any unrecoverable problem).
     */
    private function composeCard(?string $templatePath, array $overlays, callable $resolve, ?\DateTimeInterface $cardDate = null): ?string
    {
        if (! $templatePath || empty($overlays)) {
            return null;
        }

        try {
            $templateBytes = Storage::disk('r2')->get($templatePath);
        } catch (\Throwable $e) {
            Log::warning('Greeting card template fetch failed', [
                'path' => $templatePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
        if (! $templateBytes) {
            Log::warning('Greeting card template not found on R2', ['path' => $templatePath]);

            return null;
        }

        $image = imagecreatefromstring($templateBytes);
        if (! $image) {
            Log::warning('Failed to decode template bytes into GD image', ['path' => $templatePath]);

            return null;
        }

        $fontPath = $this->resolveFontPath();

        foreach ($overlays as $overlay) {
            $this->applyOverlay($image, $overlay, $resolve, $fontPath, $cardDate);
        }

        ob_start();
        imagepng($image);
        $pngBytes = (string) ob_get_clean();
        imagedestroy($image);

        return $pngBytes;
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
     * Apply a single overlay (text or image) onto the card. $resolve maps
     * a field key to its rendered value (donation vs seva resolver).
     */
    private function applyOverlay(\GdImage $image, array $overlay, callable $resolve, ?string $fontPath, ?\DateTimeInterface $cardDate = null): void
    {
        $type = $overlay['type'] ?? 'text';
        $fieldKey = $overlay['field_key'] ?? null;

        if (! $fieldKey) {
            return;
        }

        $value = $resolve($fieldKey);
        $isBlank = ($value === null || $value === '');

        // A blank TEXT overlay draws nothing — an empty caption is correct,
        // and a placeholder where a donor's name should be would be worse
        // than the gap. A blank IMAGE overlay is different: an admin can
        // define a photo-upload extra field and leave it optional, and a
        // donor who skips it used to get a card with a hole in it. That
        // falls through to the day's darshan photo instead (2026-08-13,
        // logo; darshan since 2026-08-16).
        if ($isBlank && $type !== 'image') {
            return;
        }

        if ($type === 'text') {
            // Route Gujarati/Hindi values (devotee names!) to a font with
            // their glyphs — DejaVu renders Indic text as tofu boxes.
            $this->applyTextOverlay(
                $image,
                $overlay,
                (string) $value,
                // Bold is a separate FILE for GD, so the weight has to be
                // decided here, at font-resolution time.
                ScriptFont::forText((string) $value, (bool) ($overlay['bold'] ?? false)) ?? $fontPath,
            );
        } elseif ($type === 'image') {
            $this->applyImageOverlay($image, $overlay, $isBlank ? null : (string) $value, $cardDate);
        }
    }

    /**
     * Resolve the value for a donation field key.
     * Keys starting with _ are special auto-fields.
     */
    private function resolveFieldValue(string $fieldKey, Donation $donation, string $locale = 'gu'): ?string
    {
        if (str_starts_with($fieldKey, '_')) {
            return match ($fieldKey) {
                '_donor_name' => $donation->devotee?->name,
                '_amount' => "\u{20B9}".number_format((float) $donation->amount, 2),
                '_date' => now()->format('d/m/Y'),
                '_temple_name' => $this->templeName($locale),
                default => null,
            };
        }

        $extraData = $donation->extra_data ?? [];

        return isset($extraData[$fieldKey]) ? (string) $extraData[$fieldKey] : null;
    }

    /**
     * Resolve the value for a seva-booking field key.
     */
    /**
     * The model whose artwork this donation's card is rendered from, or NULL
     * when neither the donation type nor the campaign has one configured.
     *
     * Public so the dispatching job can ask the same question to decide which
     * notification trigger to fire, instead of re-deriving the rule and
     * risking the two answers drifting apart.
     */
    public function cardSourceFor(Donation $donation): ?Model
    {
        $donation->loadMissing('donationType', 'campaign');

        $type = $donation->donationType;
        if ($type && $type->greeting_card_template && $type->greeting_card_config) {
            return $type;
        }

        $campaign = $donation->campaign;
        if ($campaign && $campaign->greeting_card_template && $campaign->greeting_card_config) {
            return $campaign;
        }

        return null;
    }

    /** True when this donation's card comes from its campaign, not its type. */
    public function cardIsFromCampaign(Donation $donation): bool
    {
        return $this->cardSourceFor($donation) instanceof DonationCampaign;
    }

    /**
     * Overlay values for a campaign card.
     *
     * Twin of resolveSevaFieldValue(). Campaigns have no extra_fields, so
     * unlike the donation-type resolver there is deliberately no fallback into
     * $donation->extra_data — an unknown key renders as nothing rather than
     * leaking whatever a donor happened to type into a dynamic field.
     */
    private function resolveCampaignFieldValue(string $fieldKey, Donation $donation, string $locale = 'gu'): ?string
    {
        if (str_starts_with($fieldKey, '_')) {
            return match ($fieldKey) {
                '_donor_name' => $donation->devotee?->name,
                '_campaign_title' => $donation->campaign?->title,
                '_sub_cause' => $donation->subCause?->title,
                '_amount' => "\u{20B9}".number_format((float) $donation->amount, 2),
                '_date' => now()->format('d/m/Y'),
                '_temple_name' => $this->templeName($locale),
                default => null,
            };
        }

        // Campaign extra fields (2026-08-13). A campaign donation is still a
        // Donation, so its answers live in the same extra_data column the
        // donation-type resolver reads — including image fields, whose value
        // is the R2 key of the upload.
        $extraData = $donation->extra_data ?? [];

        return isset($extraData[$fieldKey]) ? (string) $extraData[$fieldKey] : null;
    }

    private function resolveSevaFieldValue(string $fieldKey, SevaBooking $booking, string $locale = 'gu'): ?string
    {
        if (! str_starts_with($fieldKey, '_')) {
            // Seva extra fields (2026-08-13) — the devotee's answers on the
            // booking, mirroring donations' extra_data.
            $extraData = $booking->extra_data ?? [];

            return isset($extraData[$fieldKey]) ? (string) $extraData[$fieldKey] : null;
        }

        return match ($fieldKey) {
            '_donor_name' => $booking->devotee_name_for_seva ?: $booking->devotee?->name,
            '_seva_name' => $this->sevaNameForLocale($booking, $locale),
            '_booking_date' => $booking->booking_date?->format('d/m/Y'),
            '_slot' => $booking->slot_time_label,
            '_amount' => "\u{20B9}".number_format((float) $booking->total_amount, 2),
            '_sankalp' => $booking->sankalp,
            '_date' => now()->format('d/m/Y'),
            '_temple_name' => $this->templeName($locale),
            default => null,
        };
    }

    private function sevaNameForLocale(SevaBooking $booking, string $locale): ?string
    {
        $seva = $booking->seva;
        if (! $seva) {
            return null;
        }

        return match ($locale) {
            'hi' => $seva->name_hi ?: $seva->name_gu,
            'en' => $seva->name_en ?: $seva->name_gu,
            default => $seva->name_gu,
        };
    }

    private function templeName(string $locale): string
    {
        return SystemSetting::getLocalized('trust_name', $locale, 'Shree Patadiya Hanumanji Seva Trust');
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
        // Overlays saved before the bold toggle existed have no key → normal.
        $bold = (bool) ($overlay['bold'] ?? false);

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
            $png = ShapedText::render($text, $fontSize, $colorHexForShaping, $width > 0 ? $width : null, null, $bold);
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
    /**
     * @param  string|null  $storagePath  R2 key of the donor's upload, or NULL
     *                                    when they did not upload one — in which case the trust logo is drawn so
     *                                    the card never ships with an empty box.
     */
    private function applyImageOverlay(\GdImage $image, array $overlay, ?string $storagePath, ?\DateTimeInterface $cardDate = null): void
    {
        $bytes = $storagePath === null
            ? $this->fallbackOverlayBytes($cardDate)
            : $this->overlayBytesFromR2($storagePath) ?? $this->fallbackOverlayBytes($cardDate);

        if (! $bytes) {
            // Even the fallback is unreadable. A card with a gap still beats
            // no card at all, so draw nothing and carry on.
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

    /** The donor's uploaded image, or NULL when it cannot be read. */
    private function overlayBytesFromR2(string $storagePath): ?string
    {
        try {
            $bytes = Storage::disk('r2')->get($storagePath);
        } catch (\Throwable $e) {
            Log::warning('Greeting card overlay image fetch failed', [
                'path' => $storagePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $bytes) {
            Log::warning('Greeting card overlay image not found on R2', ['path' => $storagePath]);

            return null;
        }

        return $bytes;
    }

    /**
     * Stand-in artwork for an image overlay the donor left empty, in order:
     *
     *   1. THE DAILY DARSHAN PHOTO for the card's own day — that day's
     *      upload, else the last one before it, else the newest on record
     *      (DailyDarshanPhoto::forDate). Requested 2026-08-16: a card whose
     *      photo slot showed the flat logo now shows Hanumanji as he was
     *      given darshan on the day the donation was made or the seva
     *      performed, which is the whole point of the keepsake.
     *   2. `greeting_card_fallback_image` (an R2 key) — dedicated artwork
     *      the trust can set without a deploy, used when no darshan photo
     *      exists at all (a fresh install, or every photo deactivated).
     *   3. The bundled trust logo — a LOCAL file, which is why this cannot
     *      simply reuse the R2 reader above.
     *
     * Anchored on $cardDate, not on today(), so a card regenerated after the
     * r2_private sweep rebuilds with the same photo it was delivered with.
     * Bytes are memoised per instance because the day-of seva sweep renders
     * many cards in one process and would otherwise refetch one photo N times.
     */
    private function fallbackOverlayBytes(?\DateTimeInterface $cardDate = null): ?string
    {
        $memoKey = $cardDate?->format('Y-m-d') ?? 'today';

        if (array_key_exists($memoKey, $this->fallbackBytesMemo)) {
            return $this->fallbackBytesMemo[$memoKey];
        }

        return $this->fallbackBytesMemo[$memoKey] = $this->resolveFallbackOverlayBytes($cardDate);
    }

    /** @see fallbackOverlayBytes — the uncached body. */
    private function resolveFallbackOverlayBytes(?\DateTimeInterface $cardDate): ?string
    {
        $darshanPath = DailyDarshanPhoto::forDate($cardDate)?->overlaySourcePath();

        if ($darshanPath) {
            $bytes = $this->overlayBytesFromR2($darshanPath);
            if ($bytes) {
                return $bytes;
            }
            // Row exists but its file is gone from R2 — keep descending
            // rather than punching a hole in the card.
        }

        $configured = SystemSetting::getValue('greeting_card_fallback_image', '');

        if ($configured !== '') {
            $bytes = $this->overlayBytesFromR2($configured);
            if ($bytes) {
                return $bytes;
            }
            // Configured but unreadable — fall through to the bundled logo
            // rather than leaving a hole.
        }

        $logo = public_path('images/shree-pataliya-hanumanji-logo.png');

        if (! is_readable($logo)) {
            Log::warning('Greeting card fallback logo missing', ['path' => $logo]);

            return null;
        }

        return file_get_contents($logo) ?: null;
    }
}
