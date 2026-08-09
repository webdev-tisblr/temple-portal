<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * One patched app string (key × locale). See the migration doc-block —
 * this is an emergency hotfix channel, not a wording CMS: rows exist
 * only while a fix hasn't shipped in a store build yet.
 */
class AppStringOverride extends Model
{
    protected $table = 'temple_app_string_overrides';

    public const CACHE_KEY = 'app_string_overrides_payload';

    protected $fillable = [
        'key',
        'locale',
        'value',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Bust the payload every phone reads (GET /api/v1/content/app-strings,
     * 300s cache) whenever a row changes.
     *
     * CAVEAT: these are Eloquent MODEL events. Filament's row DeleteAction
     * and DeleteBulkAction both call $record->delete() on instances, so the
     * admin screen is covered — but a query-builder mass delete/update
     * (`AppStringOverride::where(...)->delete()` in tinker, a migration or a
     * seeder) fires nothing and leaves the cache serving a removed fix for
     * up to 5 minutes. Follow any such write with
     * `Cache::forget(AppStringOverride::CACHE_KEY)`.
     */
    protected static function booted(): void
    {
        $bust = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($bust);
        static::deleted($bust);
    }
}
