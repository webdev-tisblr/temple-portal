<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    /** Cache key for the full key→value settings map. */
    public const CACHE_KEY = 'system_settings_map';

    protected $table = 'temple_system_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'value',
        'group',
        'description',
        'updated_by',
        'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Filament (and any other direct model writes) bypass setValue(),
        // so invalidate the cached map on every persisted change/delete.
        static::saved(fn () => self::forgetCache());
        static::deleted(fn () => self::forgetCache());
    }

    public static function getValue(string $key, string $default = ''): string
    {
        // Read from a single cached key→value map instead of one SELECT
        // per call (the footer alone fires ~8 lookups per page render).
        // Cache::forever is invalidated in setValue() / forgetCache().
        $map = Cache::rememberForever(self::CACHE_KEY, fn () => static::pluck('value', 'key')->all());

        return $map[$key] ?? $default;
    }

    /**
     * Locale-aware setting lookup. Convention (matches trust_tagline):
     * the bare key holds the Gujarati/default value; hi/en live in
     * `{key}_hi` / `{key}_en` and fall back to the bare key when empty.
     */
    public static function getLocalized(string $key, ?string $locale = null, string $default = ''): string
    {
        $locale = $locale ?: app()->getLocale();

        if (in_array($locale, ['hi', 'en'], true)) {
            $value = self::getValue("{$key}_{$locale}");
            if ($value !== '') {
                return $value;
            }
        }

        return self::getValue($key, $default);
    }

    public static function setValue(string $key, string $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()]
        );

        self::forgetCache();
    }

    /** Bust the cached settings map. Call after any direct write to the table. */
    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        // API temple-info payload built from these settings — now stored
        // per-locale (LocalizedCache), so bust every language variant.
        \App\Support\LocalizedCache::forget('content.temple_info.v1');
    }
}
