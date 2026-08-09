<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * One place that answers "which language should we render this document in
 * for this devotee?".
 *
 * The answer lives in `temple_devotees.language` (an `App\Enums\Language`
 * backed enum, gu|hi|en). It is seeded from the request locale when the
 * devotee row is created (Devotee::booted) and editable in admin.
 *
 * WHY a shared helper rather than resolving at the call site: PDF/greeting
 * generation runs from ~15 different places (Filament actions, web + API
 * download controllers, and queued jobs). Queue workers have no request, so
 * app()->getLocale() there is always the default `gu` — a controller-level
 * fix would silently miss every async path. Services resolve the locale from
 * the devotee themselves and wrap the render in withLocale().
 */
final class DevoteeLocale
{
    public const SUPPORTED = ['gu', 'hi', 'en'];

    public const FALLBACK = 'gu';

    /**
     * Preferred render language for a devotee (defaults to Gujarati).
     * Accepts null so callers can pass `$booking->devotee` unguarded.
     */
    public static function for(?Model $devotee): string
    {
        $locale = $devotee?->getAttribute('language');
        $locale = $locale instanceof \BackedEnum ? $locale->value : $locale;

        return in_array($locale, self::SUPPORTED, true) ? $locale : self::FALLBACK;
    }

    /**
     * Run $callback with the application locale switched to $locale, always
     * restoring the previous locale — including when $callback throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withLocale(string $locale, callable $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
