<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignSubCause extends Model
{
    protected $table = 'temple_campaign_sub_causes';

    protected $fillable = [
        'campaign_id',
        'title_gu',
        'title_hi',
        'title_en',
        'goal_amount',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'goal_amount' => 'decimal:2',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function getTitleAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "title_{$locale}";

        return $this->$field ?: $this->title_gu;
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DonationCampaign::class, 'campaign_id');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'sub_cause_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
