<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DonationType extends Model
{
    use SoftDeletes;

    protected $table = 'temple_donation_types';

    protected $fillable = [
        'name_gu',
        'name_hi',
        'name_en',
        'slug',
        'description',
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
