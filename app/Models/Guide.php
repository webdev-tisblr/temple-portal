<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guide extends Model
{
    use HasManagedImages;

    protected $table = 'temple_guides';

    protected static function booted(): void
    {
        $bust = static fn () => \App\Support\LocalizedCache::forget('guides');
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return ['cover_image' => 'r2'];
    }

    protected $fillable = [
        'category_id',
        'title_gu',
        'title_hi',
        'title_en',
        'summary_gu',
        'summary_hi',
        'summary_en',
        'body_gu',
        'body_hi',
        'body_en',
        'cover_image',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'category_id' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Locale-aware accessors, falling back to Gujarati (primary language).
     */
    public function getTitleAttribute(): ?string
    {
        $locale = app()->getLocale();
        return $this->attributes["title_{$locale}"]
            ?? $this->attributes['title_gu']
            ?? null;
    }

    public function getSummaryAttribute(): ?string
    {
        $locale = app()->getLocale();
        return $this->attributes["summary_{$locale}"]
            ?? $this->attributes['summary_gu']
            ?? null;
    }

    public function getBodyAttribute(): ?string
    {
        $locale = app()->getLocale();
        return $this->attributes["body_{$locale}"]
            ?? $this->attributes['body_gu']
            ?? null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(GuideCategory::class, 'category_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(GuideMedia::class, 'guide_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
