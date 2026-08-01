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

    protected static function booted(): void
    {
        $bust = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($bust);
        static::deleted($bust);
    }
}
