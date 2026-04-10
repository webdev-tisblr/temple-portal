<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DonationCampaign extends Model
{
    use SoftDeletes;

    protected $table = 'temple_donation_campaigns';

    protected $fillable = [
        'title_gu',
        'title_hi',
        'title_en',
        'slug',
        'description_gu',
        'description_hi',
        'description_en',
        'writeup_gu',
        'writeup_hi',
        'writeup_en',
        'goal_amount',
        'raised_amount',
        'donor_count',
        'image_path',
        'media',
        'faqs',
        'start_date',
        'end_date',
        'is_active',
        'show_donor_list',
        'is_featured',
    ];

    protected $casts = [
        'goal_amount' => 'decimal:2',
        'raised_amount' => 'decimal:2',
        'donor_count' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'show_donor_list' => 'boolean',
        'is_featured' => 'boolean',
        'media' => 'array',
        'faqs' => 'array',
    ];

    public function getTitleAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "title_{$locale}";
        return $this->$field ?? $this->title_gu;
    }

    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "description_{$locale}";
        return $this->$field ?? $this->description_gu;
    }

    public function getWriteupAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "writeup_{$locale}";
        return $this->$field ?? $this->writeup_gu;
    }

    public function donations(): HasMany
    {
        return $this->hasMany(Donation::class, 'campaign_id');
    }
}
