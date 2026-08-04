<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SevaCategory extends Model
{
    protected $table = 'temple_seva_categories';

    protected $fillable = [
        'slug',
        'name_gu',
        'name_hi',
        'name_en',
        'sort_order',
    ];

    /** slug => localized name for the current request, memoized. */
    private static ?array $namesBySlug = null;

    protected static function booted(): void
    {
        // Category names feed the localized web tabs, the home-page
        // category cards and the app's chip endpoint — bust those
        // caches whenever the list changes.
        $bust = function (): void {
            \App\Support\LocalizedCache::forget('seva.categories');
            Cache::forget('homepage_seva_categories');
            self::$namesBySlug = null;
        };
        static::saved($bust);
        static::deleted($bust);
    }

    public function getNameAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "name_{$locale}";

        return $this->$field ?? $this->name_gu;
    }

    public function sevas()
    {
        return $this->hasMany(Seva::class, 'category', 'slug');
    }

    /** slug => localized display name, ordered by admin sort. */
    public static function namesBySlug(): array
    {
        return self::$namesBySlug ??= self::orderBy('sort_order')
            ->get()
            ->mapWithKeys(fn (self $c) => [$c->slug => $c->name])
            ->all();
    }

    /**
     * Localized display name for a category slug; slugs not in the managed
     * list (legacy/orphaned) fall back to a title-cased slug.
     */
    public static function displayName(?string $slug): string
    {
        if ($slug === null || $slug === '') {
            return '';
        }

        return self::namesBySlug()[$slug] ?? ucfirst($slug);
    }
}
