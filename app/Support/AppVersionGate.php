<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;

/**
 * Resolves the minimum app version a devotee may run, PER PLATFORM.
 *
 * Why per platform: Android ships within hours of upload, Apple review takes
 * days. With one shared minimum, forcing a version Apple has not yet approved
 * walls every iOS devotee behind an Update button that opens a listing which
 * does not have that version — they cannot comply, and the trust has locked
 * its own users out until Apple happens to approve. Splitting the setting is
 * what lets Android be forced the day it ships and iOS be forced afterwards.
 *
 * ── The legacy-client rule ───────────────────────────────────────────────
 * Builds already in the field (1.5.0–1.5.2, ~2,500 devices at the time of
 * writing) only know the single `min_supported_version` key and cannot tell
 * the platforms apart. They are therefore served the LOWER of the two
 * resolved minimums — the most permissive answer — so a tightening meant for
 * one platform can never wall the other's older builds. New builds read the
 * platform-specific key and get the exact rule.
 *
 * ── Fail open ────────────────────────────────────────────────────────────
 * A blank or unparseable setting means NO minimum, not "version zero". The
 * gate is off until somebody deliberately turns it on; an unconfigured
 * install, or a typo, must never lock anyone out.
 */
final class AppVersionGate
{
    public const PLATFORM_ANDROID = 'android';

    public const PLATFORM_IOS = 'ios';

    /**
     * The minimum for one platform: its own setting, falling back to the
     * shared `app_min_version`, falling back to no minimum at all.
     */
    public static function minFor(string $platform): string
    {
        $platformValue = AppVersion::normalise(
            (string) SystemSetting::getValue("app_min_version_{$platform}", '')
        );

        if ($platformValue !== '') {
            return $platformValue;
        }

        return AppVersion::normalise(
            (string) SystemSetting::getValue('app_min_version', '')
        );
    }

    /**
     * What to serve as `min_supported_version` to a client that cannot tell
     * us its platform — every build shipped before the split.
     *
     * Deliberately the LOWER of the two. Erring permissive here costs a few
     * devotees staying on an old build a little longer; erring strict locks
     * a whole platform out of the app.
     */
    public static function sharedMin(): string
    {
        $android = self::minFor(self::PLATFORM_ANDROID);
        $ios = self::minFor(self::PLATFORM_IOS);

        // A platform with NO minimum is the most permissive answer there is,
        // so it wins outright — blank is a floor of nothing, not a missing
        // opinion to be ignored. Forcing Android while iOS is unconfigured
        // must leave every old iPhone build untouched; returning Android's
        // number here would wall them through the legacy key, which is the
        // exact lockout this whole class exists to prevent.
        if ($android === '' || $ios === '') {
            return '';
        }

        return AppVersion::lower($android, $ios);
    }
}
