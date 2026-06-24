<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hall extends Model
{
    use HasManagedImages;

    protected $table = 'temple_halls';

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

    protected $fillable = [
        'name',
        'name_gu',
        'name_hi',
        'name_en',
        'description',
        'description_gu',
        'description_hi',
        'description_en',
        'capacity',
        'price_per_day',
        'price_per_half_day',
        'amenities',
        'rules',
        'rules_gu',
        'rules_hi',
        'rules_en',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'price_per_day' => 'decimal:2',
        'price_per_half_day' => 'decimal:2',
        'amenities' => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Locale-aware name. Falls back to Gujarati (the primary language),
     * then to the legacy single-column `name` for safety.
     */
    public function getNameAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "name_{$locale}";
        return $this->attributes[$field]
            ?? $this->attributes['name_gu']
            ?? $this->attributes['name']
            ?? null;
    }

    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "description_{$locale}";
        return $this->attributes[$field]
            ?? $this->attributes['description_gu']
            ?? $this->attributes['description']
            ?? null;
    }

    public function getRulesAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "rules_{$locale}";
        return $this->attributes[$field]
            ?? $this->attributes['rules_gu']
            ?? $this->attributes['rules']
            ?? null;
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class, 'hall_id');
    }
}
