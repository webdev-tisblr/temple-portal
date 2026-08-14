<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Support\DevoteeLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Lightweight wrapper around the dispatch-time context array — handles
 * both array bags and Eloquent models, with dot-path lookups used by
 * placeholder rendering.
 *
 *   $ctx->get('donation.devotee.name')       // → "Hari"
 *   $ctx->get('booking.amount', '0')         // default fallback
 *
 * Models are auto-unwrapped: any dot segment that lands on an Eloquent
 * model walks `getAttribute` next, so callers don't need to flatten.
 */
final class NotificationContext
{
    /** Memoised result of locale() — resolution walks the context bag. */
    private ?string $resolvedLocale = null;

    public function __construct(public readonly array $data) {}

    /**
     * The language THIS delivery is written in: an explicit context
     * 'locale' (set by dispatch sites that already know the recipient,
     * eg the seva/hall reminder rules) → the devotee's saved language →
     * Gujarati.
     *
     * Previously this lived twice, privately, in the WhatsApp and push
     * drivers — so it only ever picked the message TEXT variant, and
     * placeholder values were resolved with no idea what language the
     * surrounding sentence was in. Hoisting it here is what lets
     * getLocalized() below match the two up.
     */
    public function locale(): string
    {
        if ($this->resolvedLocale !== null) {
            return $this->resolvedLocale;
        }

        $locale = $this->get('locale');

        if (! is_string($locale) || $locale === '') {
            // Dot-path rather than DevoteeLocale::for() — a dispatch
            // context may carry `devotee` as a plain array (several jobs
            // merge toArray() bags), which that Model-typed helper rejects.
            $lang = $this->get('devotee.language');
            $locale = $lang instanceof \BackedEnum ? (string) $lang->value : $lang;
        }

        return $this->resolvedLocale = is_string($locale) && in_array($locale, DevoteeLocale::SUPPORTED, true)
            ? $locale
            : DevoteeLocale::FALLBACK;
    }

    /**
     * Resolve a dot-path, preferring the recipient's language.
     *
     * Dispatch sites and admin placeholder maps both name Gujarati
     * paths, in two shapes seen in production:
     *
     *   booking.seva.name_gu   — a translated column on a model/array
     *   booking.seva_name      — a scalar the dispatch site pre-resolved
     *
     * For a non-Gujarati recipient we try the matching sibling first
     * (`..._hi` / `..._en`), and fall back to the path as written when
     * that translation is missing or blank. So a Hindi reminder body
     * renders a Hindi seva name, and a seva with no Hindi translation
     * still reads Gujarati rather than going empty.
     *
     * Gujarati recipients short-circuit — the common case costs nothing.
     */
    public function getLocalized(string $path, mixed $default = null): mixed
    {
        $locale = $this->locale();

        if ($locale !== 'gu') {
            $base = str_ends_with($path, '_gu') ? substr($path, 0, -3) : $path;
            $translated = $this->get($base.'_'.$locale);

            if (is_string($translated) ? trim($translated) !== '' : $translated !== null) {
                return $translated;
            }
        }

        return $this->get($path, $default);
    }

    /**
     * Build a new context with extra keys merged on top of the current
     * data. Used by NotificationService's admin_role fan-out to inject
     * a per-recipient `admin` into the context for each delivery without
     * mutating the caller's original context.
     */
    public function with(array $extras): self
    {
        return new self(array_replace($this->data, $extras));
    }

    /** Pull a value at a dot-path, default applied on miss/null. */
    public function get(string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $cursor = $this->data;

        foreach ($segments as $segment) {
            if ($cursor instanceof Model) {
                $cursor = $cursor->getAttribute($segment) ?? $cursor->{$segment} ?? null;

                continue;
            }
            if (is_array($cursor)) {
                $cursor = Arr::get($cursor, $segment);

                continue;
            }
            if (is_object($cursor) && (isset($cursor->{$segment}) || method_exists($cursor, '__get'))) {
                $cursor = $cursor->{$segment} ?? null;

                continue;
            }
            $cursor = null;
            break;
        }

        return $cursor ?? $default;
    }

    /**
     * Substitute every `{{ token }}` (whitespace-tolerant) in $template
     * using either an explicit placeholder map (placeholder → dot-path)
     * or, when no map row matches, the token itself as a dot-path.
     *
     * Missing values become an empty string by default.
     */
    public function render(string $template, ?array $placeholderMap = null): string
    {
        return (string) Str::of($template)->replaceMatches(
            '/\\{\\{\\s*([a-zA-Z0-9_.]+)\\s*\\}\\}/',
            function ($m) use ($placeholderMap) {
                $token = $m[1];
                $path = $placeholderMap[$token] ?? $token;

                return self::formatForDisplay($this->getLocalized($path));
            }
        );
    }

    /**
     * Resolve a dot-path AND format the result for human display.
     *
     * Drivers (email body, WhatsApp parameters, SMS, push) all want
     * the same coercion rules:
     *
     *   • null                → empty string
     *   • Carbon / DateTime   → 'd M Y' for pure dates, 'd M Y H:i'
     *                           when there's a non-zero time. Without
     *                           this an Eloquent `date` cast renders
     *                           as "2026-05-15 00:00:00" because
     *                           Carbon's __toString is full Y-m-d H:i:s.
     *   • Stringable object   → __toString()
     *   • array / unknown obj → empty string (never inline raw bag)
     *   • scalars             → (string) cast
     *
     * This is the single coercion point — every driver calls it
     * instead of casting (string) directly so date formatting (and
     * future per-type rules) stay consistent across channels.
     */
    public function getForDisplay(string $path, string $default = ''): string
    {
        $value = $this->getLocalized($path);
        if ($value === null) {
            return $default;
        }

        return self::formatForDisplay($value);
    }

    public static function formatForDisplay(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            // Pure date cast → time is exactly midnight → show date only.
            // Anything else has a meaningful time → include it.
            $hasTime = (int) $value->format('His') !== 0;

            return $value->format($hasTime ? 'd M Y H:i' : 'd M Y');
        }

        // PHP enum casts (Eloquent's `'column' => SomeEnum::class`)
        // need special handling — they're objects with no __toString,
        // so the generic "object → empty" fallback below would silently
        // turn every enum-typed column into an empty WhatsApp param.
        // BackedEnum gets its primitive value; UnitEnum falls back to
        // the case name. Hit in donations where donation_type is cast
        // to DonationTypeEnum — see 2026-05-14 debugging session.
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        if ($value instanceof \UnitEnum) {
            return $value->name;
        }

        // MySQL TIME columns (eg seva_bookings.slot_time) round-trip
        // through PHP as a raw "HH:MM:SS" string with no Eloquent cast.
        // Reformat to a friendly 12-hour clock so messages show
        // "7:00 AM" instead of "07:00:00".
        if (is_string($value) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('g:i A', $timestamp);
            }
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        // Arrays / unknown objects — don't inline a raw representation.
        return '';
    }

    public function asArray(): array
    {
        return $this->data;
    }
}
