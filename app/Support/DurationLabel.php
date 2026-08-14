<?php

declare(strict_types=1);

namespace App\Support;

/**
 * "How far ahead is this reminder?" as a phrase a devotee can read, in
 * their own language — the {{ time_remaining_label }} placeholder.
 *
 * WHY this exists: SevaReminderScheduler and HallReminderScheduler each
 * grew their own private humanLabel(), both hardcoded English, and they
 * had drifted apart — hall said "45 min" and treated 7 days as "1 week",
 * seva said "45 minutes" and "7 days". A Gujarati reminder body ended up
 * reading "... 3 hours માં", which is what prompted this. Both now call
 * here, so the wording is one decision in one place.
 *
 * Gujarati and Hindi do not pluralise these units the way English does:
 * Gujarati keeps કલાક/દિવસ/મિનિટ unchanged for any count, and Hindi
 * uses the oblique plural (घंटे/दिन/मिनट) for everything above one.
 */
final class DurationLabel
{
    /**
     * Unit wording per locale, as [singular, plural]. Gujarati repeats
     * the same form deliberately — it is not a copy-paste slip.
     */
    private const UNITS = [
        'gu' => [
            'week' => ['અઠવાડિયું', 'અઠવાડિયાં'],
            'day' => ['દિવસ', 'દિવસ'],
            'hour' => ['કલાક', 'કલાક'],
            'minute' => ['મિનિટ', 'મિનિટ'],
        ],
        'hi' => [
            'week' => ['सप्ताह', 'सप्ताह'],
            'day' => ['दिन', 'दिन'],
            'hour' => ['घंटा', 'घंटे'],
            'minute' => ['मिनट', 'मिनट'],
        ],
        'en' => [
            'week' => ['week', 'weeks'],
            'day' => ['day', 'days'],
            'hour' => ['hour', 'hours'],
            'minute' => ['minute', 'minutes'],
        ],
    ];

    /**
     * Render $minutes as "3 કલાક" / "3 घंटे" / "3 hours".
     *
     * Falls back to English for an unknown locale so a bad language value
     * degrades to a readable label rather than an empty placeholder —
     * Meta rejects WhatsApp sends whose parameters resolve to "".
     *
     * Whole weeks are only called a week when the offset divides evenly
     * (7 days → "1 week", 10 days stays "10 days"), matching how admins
     * phrase the offsets they configure.
     */
    public static function make(int $minutes, string $locale = 'en'): string
    {
        $units = self::UNITS[$locale] ?? self::UNITS['en'];

        [$count, $unit] = self::split(max(0, $minutes));

        $word = $count === 1 ? $units[$unit][0] : $units[$unit][1];

        return $count.' '.$word;
    }

    /**
     * The context keys a reminder dispatch should publish for one offset:
     * the bare key plus a `_gu` / `_hi` / `_en` sibling for each language.
     *
     * NotificationContext::getLocalized() prefers `<path>_<recipient
     * locale>` and falls back to the bare key, so a template mapping the
     * plain `time_remaining_label` renders in the recipient's language
     * with no admin change and no placeholder_map migration. The bare key
     * holds Gujarati to match the platform's fallback language.
     *
     * @return array<string, string>
     */
    public static function contextValues(int $minutes, string $key = 'time_remaining_label'): array
    {
        $values = [$key => self::make($minutes, DevoteeLocale::FALLBACK)];

        foreach (DevoteeLocale::SUPPORTED as $locale) {
            $values[$key.'_'.$locale] = self::make($minutes, $locale);
        }

        return $values;
    }

    /**
     * Largest unit that divides the offset exactly, so a configured
     * "1440 minutes" reads as a day rather than 1440 minutes.
     *
     * @return array{int, string}
     */
    private static function split(int $minutes): array
    {
        if ($minutes >= 10080 && $minutes % 10080 === 0) {
            return [intdiv($minutes, 10080), 'week'];
        }

        if ($minutes >= 1440 && $minutes % 1440 === 0) {
            return [intdiv($minutes, 1440), 'day'];
        }

        if ($minutes >= 60 && $minutes % 60 === 0) {
            return [intdiv($minutes, 60), 'hour'];
        }

        return [$minutes, 'minute'];
    }
}
