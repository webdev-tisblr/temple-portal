<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache wrapper for payloads that bake locale-resolved accessors
 * (title, name, description, …) into the stored value.
 *
 * Keys are suffixed with the active locale so gu/hi/en callers never
 * see each other's resolved strings; forget() busts every variant so
 * existing single-key invalidation hooks keep working.
 */
final class LocalizedCache
{
    public const LOCALES = ['gu', 'hi', 'en'];

    public static function remember(string $key, int $ttl, Closure $callback): mixed
    {
        return Cache::remember($key.'.'.app()->getLocale(), $ttl, $callback);
    }

    public static function forget(string $key): void
    {
        foreach (self::LOCALES as $locale) {
            Cache::forget($key.'.'.$locale);
        }
    }
}
