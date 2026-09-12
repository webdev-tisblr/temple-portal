<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DailyDarshanPhoto;
use App\Models\DarshanCardTemplate;
use App\Models\DonationCampaign;
use App\Models\DonationType;
use App\Models\Seva;
use App\Models\StatusTemplate;
use App\Models\SystemSetting;
use App\Support\CardOverlayPainter;
use App\Support\DevoteeLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a card exactly as a devotee would receive it, from the layout the
 * admin is editing RIGHT NOW — unsaved — with sample values in the chosen
 * language. Nothing is written anywhere: the PNG goes straight back down the
 * response and is gone.
 *
 * WHY (2026-09-12): the editor's canvas is a browser approximation. The real
 * card is drawn by pango on the server, in the real font, with the real line
 * breaks a Gujarati name produces. The greeting card is the first thing a
 * devotee sees after giving, so the admin needs to see the genuine article in
 * all three languages before saving — not discover a clipped line from a
 * devotee's screenshot.
 *
 * It composes through CardOverlayPainter — the same code path the live
 * renderers use — so whatever this shows is what will ship. The only inputs
 * it cannot take from the unsaved form are the BACKGROUND images, which are
 * read from the saved record (the canvas has the same limitation).
 */
class CardPreviewService
{
    /**
     * Owners that carry a card layout, keyed by the alias the editor posts.
     * An allow-list, not a morph map: a posted class name is never trusted.
     *
     * @var array<string, class-string<Model>>
     */
    public const OWNERS = [
        'donation_type' => DonationType::class,
        'donation_campaign' => DonationCampaign::class,
        'seva' => Seva::class,
        'darshan_card_template' => DarshanCardTemplate::class,
        'status_template' => StatusTemplate::class,
    ];

    /** Overlay payloads bigger than this are refused outright. */
    public const MAX_CONFIG_BYTES = 256 * 1024;

    public function __construct(private readonly GreetingCardService $greetingCards) {}

    /** The editor's alias for a record, or null when it has no card layout. */
    public static function aliasFor(Model $owner): ?string
    {
        foreach (self::OWNERS as $alias => $class) {
            if ($owner instanceof $class) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * PNG bytes of the sample card, or null when there is nothing to draw on
     * (no background saved yet) or the background cannot be read.
     *
     * @param  list<array<string, mixed>>  $overlays
     */
    public function render(Model $owner, array $overlays, string $locale): ?string
    {
        $locale = in_array($locale, DevoteeLocale::SUPPORTED, true) ? $locale : DevoteeLocale::FALLBACK;

        $templatePath = $this->greetingCards->templateForLocale($owner, $locale);
        if (! $templatePath) {
            return null;
        }

        try {
            $bytes = Storage::disk('r2')->get($templatePath);
        } catch (\Throwable $e) {
            Log::warning('Card preview: background fetch failed', ['path' => $templatePath, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $bytes) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);
        if (! $image instanceof \GdImage) {
            return null;
        }

        // slot labels and the like translate via __(); render under the
        // preview language so "Full Day" comes out the way the devotee's
        // card would.
        DevoteeLocale::withLocale($locale, function () use ($image, $owner, $overlays, $locale): void {
            CardOverlayPainter::compose(
                $image,
                $overlays,
                fn (string $key): ?string => $this->sampleText($owner, $key, $locale),
                fn (string $key): ?string => $this->sampleImage($owner, $key),
                $locale,
            );
        });

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /**
     * A believable value for every variable the editors offer. Names are
     * deliberately long-ish and in the card's own script, because the whole
     * point of previewing is to see whether a real Gujarati name still fits.
     */
    private function sampleText(Model $owner, string $key, string $locale): ?string
    {
        $pick = fn (string $gu, string $hi, string $en): string => match ($locale) {
            'hi' => $hi,
            'en' => $en,
            default => $gu,
        };

        $today = now()->setTimezone('Asia/Kolkata');

        return match ($key) {
            '_donor_name' => $pick('રમેશભાઈ મગનભાઈ પટેલ', 'रमेशभाई मगनभाई पटेल', 'Rameshbhai Maganbhai Patel'),
            '_amount' => "\u{20B9}".number_format(5100, 2),
            '_date' => $owner instanceof StatusTemplate ? $today->format('d M Y') : $today->format('d/m/Y'),
            '_temple_name' => SystemSetting::getLocalized('trust_name', $locale, 'Shree Patadiya Hanumanji Seva Trust'),
            '_seva_name' => $owner instanceof Seva
                ? ($owner->getAttribute("name_{$locale}") ?: $owner->getAttribute('name_gu') ?: $pick('સુંદરકાંડ પાઠ', 'सुंदरकांड पाठ', 'Sundarkand Path'))
                : $pick('સુંદરકાંડ પાઠ', 'सुंदरकांड पाठ', 'Sundarkand Path'),
            '_booking_date' => $today->copy()->addDays(7)->format('d/m/Y'),
            '_slot' => __('seva.slot_full_day'),
            '_campaign_title' => $owner instanceof DonationCampaign
                ? ($owner->getAttribute("title_{$locale}") ?: $owner->getAttribute('title_gu') ?: $pick('મંદિર જીર્ણોદ્ધાર', 'मंदिर जीर्णोद्धार', 'Temple Renovation'))
                : $pick('મંદિર જીર્ણોદ્ધાર', 'मंदिर जीर्णोद्धार', 'Temple Renovation'),
            '_sub_cause' => $pick('અન્નદાન', 'अन्नदान', 'Annadaan'),
            '_caption' => $this->sampleCaption($locale) ?? $pick('મંગળા આરતી દર્શન', 'मंगला आरती दर्शन', 'Mangala Aarti Darshan'),
            default => $this->sampleExtraField($owner, $key, $locale),
        };
    }

    /** A devotee-typed extra field: shown as its own label, so the admin can tell which slot is which. */
    private function sampleExtraField(Model $owner, string $key, string $locale): ?string
    {
        $fields = $owner->getAttribute('extra_fields');
        if (! is_array($fields)) {
            return null;
        }

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['key'] ?? null) !== $key) {
                continue;
            }

            return match ($field['type'] ?? 'text') {
                'date' => now()->setTimezone('Asia/Kolkata')->format('d/m/Y'),
                'number' => '108',
                'image' => null,
                default => (string) ($field["label_{$locale}"] ?? $field['label_gu'] ?? $field['label_en'] ?? $key),
            };
        }

        return null;
    }

    private function sampleCaption(string $locale): ?string
    {
        $photo = DailyDarshanPhoto::forDate();
        if (! $photo) {
            return null;
        }

        $caption = $photo->getAttribute("caption_{$locale}") ?: $photo->getAttribute('caption_gu');

        return is_string($caption) && trim($caption) !== '' ? $caption : null;
    }

    /**
     * Every photo slot gets what an empty slot would on a real card — that
     * day's darshan photo, else the configured fallback, else the trust logo.
     * The darshan card's own `darshan_photo` slot prefers the latest photo's
     * full image, exactly as the live renderer does.
     */
    private function sampleImage(Model $owner, string $key): ?string
    {
        if ($owner instanceof DarshanCardTemplate && $key === 'darshan_photo') {
            $path = DailyDarshanPhoto::forDate()?->image_path;
            if ($path) {
                try {
                    $bytes = Storage::disk('r2')->get($path);
                    if ($bytes) {
                        return $bytes;
                    }
                } catch (\Throwable) {
                    // fall through to the generic sample
                }
            }
        }

        return $this->greetingCards->samplePhotoBytes(now());
    }
}
