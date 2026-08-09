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
        return [
            'greeting_card_template' => 'r2',
            'greeting_card_template_hi' => 'r2',
            'greeting_card_template_en' => 'r2',
        ];
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
        'greeting_card_template_hi',
        'greeting_card_template_en',
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
     *
     * Only `name_gu` is required in the admin (DonationTypeResource), so a
     * type can legitimately have a blank `name_hi` / `name_en`. Falling back
     * to Gujarati keeps the /donate dropdown from rendering empty <option>s
     * for hi/en visitors.
     */
    public function getNameAttribute(): string
    {
        $localized = match (app()->getLocale()) {
            'hi' => $this->name_hi,
            'en' => $this->name_en,
            default => null,
        };

        return (string) (filled($localized) ? $localized : $this->name_gu);
    }

    /**
     * Locale-based description accessor, with the same Gujarati fallback
     * (then the legacy untranslated `description` column).
     */
    public function getDescriptionAttribute(): ?string
    {
        $localized = match (app()->getLocale()) {
            'hi' => $this->description_hi,
            'en' => $this->description_en,
            default => null,
        };

        if (filled($localized)) {
            return $localized;
        }

        return $this->description_gu ?: ($this->attributes['description'] ?? null);
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
