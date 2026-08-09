<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Builder + validator for the `patadiyahanumanji://` custom-scheme links
 * the website hands back to the mobile app (item 3.2).
 *
 * Contract (also written up in specs/03-deeplink-contract.md):
 *
 *     patadiyahanumanji://<intent>[?<param>=<value>&...]
 *
 * `<intent>` is the URL *host*, and its vocabulary is deliberately the
 * SAME one `DeepLinkRouter` already understands for FCM pushes
 * (`data.intent` + `data.intent_params`) — one list, two transports, so
 * a screen added for pushes is reachable from the website for free.
 *
 * Everything here is an allowlist: an unknown intent collapses to
 * `home` and unknown/oversized params are dropped, because the intent
 * ultimately originates from an app-supplied field on the handoff
 * token.
 */
final class AppDeepLink
{
    public const SCHEME = 'patadiyahanumanji';

    /**
     * Intents the shipped app understands today (deep_link_router.dart).
     *
     * @var list<string>
     */
    public const INTENTS = [
        'home',
        'donate',
        'campaigns',
        'campaign-detail',
        'seva-detail',
        'events',
        'event-detail',
        'halls',
        'store',
        'profile',
        'contact',
        'inbox',
        'guides',
        'guide-detail',
    ];

    /**
     * Intents added for the web donation round trip. The app side lands
     * with the Flutter half of 3.2; until then they degrade to `home`
     * inside DeepLinkRouter's existing `default:` branch, so emitting
     * them is safe on v1.4.8 builds.
     *
     * @var list<string>
     */
    public const RETURN_INTENTS = [
        'donate-thanks',
        'donation-history',
        'receipts',
    ];

    /**
     * Query keys allowed on a deep link. Anything else is dropped —
     * these links are built from a value the app posted, so treat it as
     * untrusted input.
     *
     * @var list<string>
     */
    public const PARAM_KEYS = ['id', 'donation', 'campaign', 'seva', 'booking', 'order', 'source'];

    private const PARAM_MAX_LENGTH = 64;

    /** Every intent that may appear in a link or on a handoff token. */
    public static function allIntents(): array
    {
        return array_merge(self::INTENTS, self::RETURN_INTENTS);
    }

    public static function isValidIntent(?string $intent): bool
    {
        return $intent !== null && in_array($intent, self::allIntents(), true);
    }

    /**
     * Keep only allowlisted, scalar, short params — in a stable order so
     * the emitted URL is deterministic (handy for tests and for cache
     * comparisons).
     *
     * @param  array<array-key, mixed>  $params
     * @return array<string, string>
     */
    public static function sanitizeParams(array $params): array
    {
        $clean = [];

        foreach (self::PARAM_KEYS as $key) {
            if (! array_key_exists($key, $params)) {
                continue;
            }

            $value = $params[$key];

            if ($value === null || is_bool($value) || is_array($value) || is_object($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '' || mb_strlen($value) > self::PARAM_MAX_LENGTH) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Build `patadiyahanumanji://<intent>?<params>`.
     *
     * @param  array<array-key, mixed>  $params
     */
    public static function build(?string $intent, array $params = []): string
    {
        $intent = self::isValidIntent($intent) ? (string) $intent : 'home';

        $query = http_build_query(self::sanitizeParams($params), '', '&', PHP_QUERY_RFC3986);

        return self::SCHEME.'://'.$intent.($query === '' ? '' : '?'.$query);
    }

    /**
     * The link that returns an app-originated browser session to the
     * screen it came from. `$extraParams` lets a page enrich it (the
     * thank-you page adds the completed donation id).
     *
     * @param  array<array-key, mixed>  $extraParams
     */
    public static function forSession(array $extraParams = []): string
    {
        $intent = session('from_app_intent');
        $params = session('from_app_intent_params', []);

        return self::build(
            is_string($intent) ? $intent : 'home',
            array_merge(is_array($params) ? $params : [], $extraParams, ['source' => 'web']),
        );
    }
}
