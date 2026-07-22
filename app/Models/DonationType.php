<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DonationType extends Model
{
    use HasManagedImages;

    protected $table = 'temple_donation_types';

    protected static function booted(): void
    {
        // Bust the API donation-types list on any admin change.
        $bust = fn () => \App\Support\LocalizedCache::forget('donation_types.active');
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return ['greeting_card_template' => 'r2'];
    }

    protected $fillable = [
        'name_gu',
        'name_hi',
        'name_en',
        'slug',
        'description',
        'description_gu',
        'description_hi',
        'description_en',
        'extra_fields',
        'greeting_card_config',
        'greeting_card_template',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'extra_fields' => 'array',
        'greeting_card_config' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Locale-based name accessor.
     */
    public function getNameAttribute(): string
    {
        $locale = app()->getLocale();

        return match ($locale) {
            'hi' => $this->name_hi,
            'en' => $this->name_en,
            default => $this->name_gu,
        };
    }

    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "description_{$locale}";

        return $this->$field ?: ($this->description_gu ?: ($this->attributes['description'] ?? null));
    }

    /**
     * Relationship: donations.
     */
    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'donation_type_id');
    }

    /**
     * Scope: active donation types.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
