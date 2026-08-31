<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Comparing app version strings.
 *
 * Single home for a comparison that now decides whether a devotee is LOCKED
 * OUT of the app (AppVersionGate), so it cannot be allowed the two slightly
 * different definitions it had before — one on SyncAppStoreVersion, one in
 * the Flutter AppConfigService.
 *
 * Mirrors AppVersion in the Flutter app; the two must agree, or the server
 * and the client disagree about who is below the minimum. A plain string
 * compare is the trap being avoided here: it calls 1.10.0 older than 1.9.0.
 */
final class AppVersion
{
    /**
     * Strip everything but the dotted numeric core.
     *
     * Apple returns "1.5.0 (35)" and Flutter builds carry "1.5.2+37"; both
     * must reduce to "1.5.2"-shaped input or a build number gets compared as
     * a patch segment. Returns '' when there is no number at all, which
     * callers read as "unset", never as version zero.
     */
    public static function normalise(string $version): string
    {
        return preg_match('/\d+(?:\.\d+)*/', $version, $m) === 1 ? $m[0] : '';
    }

    /** Semver-ish "is $candidate newer than $current", numeric, left to right. */
    public static function isNewer(string $candidate, string $current): bool
    {
        $a = self::segments($candidate);
        $b = self::segments($current);
        $length = max(count($a), count($b));

        for ($i = 0; $i < $length; $i++) {
            $left = $a[$i] ?? 0;
            $right = $b[$i] ?? 0;
            if ($left !== $right) {
                return $left > $right;
            }
        }

        return false;
    }

    /**
     * The lower of two versions, both assumed present.
     *
     * Deliberately has NO opinion about blank input — "which of these is
     * lower" and "what does an unset minimum mean" are different questions,
     * and answering both here is how the second one gets the wrong answer.
     * AppVersionGate::sharedMin() owns the blank rule.
     */
    public static function lower(string $a, string $b): string
    {
        return self::isNewer($a, $b) ? $b : $a;
    }

    /** @return list<int> */
    private static function segments(string $version): array
    {
        $normalised = self::normalise($version);

        return $normalised === ''
            ? [0]
            : array_map('intval', explode('.', $normalised));
    }
}
