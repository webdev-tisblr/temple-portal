<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuideCategory extends Model
{
    protected $table = 'temple_guide_categories';

    protected static function booted(): void
    {
        // One cache key family covers the whole guides API (list + detail
        // reads through it) — bust it on any admin change.
        $bust = static fn () => \App\Support\LocalizedCache::forget('guides');
        static::saved($bust);
        static::deleted($bust);
    }

    protected $fillable = [
        'name_gu',
        'name_hi',
        'name_en',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Locale-aware name, falling back to Gujarati (primary language).
     */
    public function getNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        return $this->attributes["name_{$locale}"]
            ?? $this->attributes['name_gu']
            ?? null;
    }

    public function guides(): HasMany
    {
        return $this->hasMany(Guide::class, 'category_id');
    }
}
