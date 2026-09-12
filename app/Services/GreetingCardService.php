<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyDarshanPhoto;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Support\CardOverlayPainter;
use App\Support\DevoteeLocale;
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
            $locale,
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
                $locale,
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
    public function templateForLocale(Model $owner, string $locale): ?string
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
     *
     * The painting itself is CardOverlayPainter — the same code the admin's
     * preview and the status/darshan renderers use, so a card cannot differ
     * from what the editor showed.
     */
    private function composeCard(?string $templatePath, array $overlays, callable $resolve, ?\DateTimeInterface $cardDate = null, string $locale = 'gu'): ?string
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

        CardOverlayPainter::compose(
            $image,
            $overlays,
            $resolve,
            fn (string $fieldKey): ?string => $this->imageBytesFor($resolve($fieldKey), $cardDate),
            $locale,
        );

        ob_start();
        imagepng($image);
        $pngBytes = (string) ob_get_clean();
        imagedestroy($image);

        return $pngBytes;
    }

    /**
     * Bytes for an image overlay. $storagePath is the devotee's upload (an R2
     * key in extra_data) — or NULL when they did not upload one, in which case
     * the fallback ladder (that day's darshan photo → configured artwork →
     * trust logo) fills the slot so the card never ships with a hole in it.
     */
    private function imageBytesFor(?string $storagePath, ?\DateTimeInterface $cardDate): ?string
    {
        if ($storagePath === null || $storagePath === '') {
            return $this->fallbackOverlayBytes($cardDate);
        }

        return $this->overlayBytesFromR2($storagePath) ?? $this->fallbackOverlayBytes($cardDate);
    }

    /**
     * The photo an EMPTY image slot would receive on a card dated $cardDate —
     * exposed for the admin preview so a sample card shows the same darshan
     * photo a real one would.
     */
    public function samplePhotoBytes(?\DateTimeInterface $cardDate = null): ?string
    {
        return $this->fallbackOverlayBytes($cardDate);
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
                '_date' => $this->cardDate($donation->created_at),
                '_temple_name' => $this->templeName($locale),
                default => null,
            };
        }

        $extraData = $donation->extra_data ?? [];

        return isset($extraData[$fieldKey]) ? (string) $extraData[$fieldKey] : null;
    }

    /**
     * The `{{ _date }}` on a card: the day of the gift or booking, NOT the day
     * of rendering. r2_private is a regenerable cache, so a card is often
     * re-rendered weeks later when a devotee re-opens it — with now() the
     * date on their keepsake silently changed on every regeneration
     * (fixed 2026-09-12).
     */
    private function cardDate(?\DateTimeInterface $when): string
    {
        return ($when ?? now())->format('d/m/Y');
    }

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
                '_date' => $this->cardDate($donation->created_at),
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
            '_date' => $this->cardDate($booking->created_at),
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
