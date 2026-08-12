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
     * `extra_fields` with a resolved `label` on every entry.
     *
     * The raw JSON stores three parallel columns (`label_gu`, `label_hi`,
     * `label_en`) but only `label_gu` and `label_en` are required in the
     * admin, so `label_hi` is routinely blank. Until 2026-08-12 BOTH
     * consumers — the website's Alpine form and the Flutter donate screen —
     * hardcoded `label_gu || label_en`, so a Hindi or English donor was
     * shown Gujarati labels on every dynamic field even though the rest of
     * the page was translated.
     *
     * Resolution happens HERE, once, so the two surfaces cannot drift: the
     * same fallback chain the name/description accessors use (requested
     * locale → Gujarati → English → the raw key, which is never blank).
     * Consumers read `label` and never touch the `label_*` columns, exactly
     * as they already read `name` rather than `name_gu`.
     */
    public function localizedExtraFields(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return collect($this->extra_fields ?? [])
            ->filter(fn ($field) => is_array($field))
            ->map(function (array $field) use ($locale): array {
                $localized = match ($locale) {
                    'hi' => $field['label_hi'] ?? null,
                    'en' => $field['label_en'] ?? null,
                    default => null,
                };

                $field['label'] = (string) (filled($localized)
                    ? $localized
                    : ($field['label_gu'] ?? $field['label_en'] ?? $field['key'] ?? ''));

                return $field;
            })
            ->values()
            ->all();
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
