<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Admin-uploaded greeting/status templates that devotees personalise on
 * demand (name + photo) and share to WhatsApp/socials.
 *
 * Column names deliberately mirror the greeting-card feature
 * (greeting_card_template / greeting_card_config) so the existing
 * drag-drop overlay editor blade works unchanged.
 */
class StatusTemplate extends Model
{
    use HasManagedImages;

    protected $table = 'temple_status_templates';

    protected static function booted(): void
    {
        $bust = fn () => Cache::forget('content.status_templates.v1');
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return ['greeting_card_template' => 'r2'];
    }

    protected $fillable = [
        'title_gu', 'title_hi', 'title_en',
        'share_text_gu', 'share_text_hi', 'share_text_en',
        'greeting_card_template', 'greeting_card_config',
        'is_active', 'sort_order',
    ];

    protected $casts = [
        'greeting_card_config' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The overlay editor lists $record->extra_fields as placeable image
     * variables — expose the devotee photo slot so admins can position it.
     */
    public function getExtraFieldsAttribute(): array
    {
        return [
            ['key' => 'user_photo', 'label' => 'Devotee Photo', 'type' => 'image'],
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function getTitleAttribute(): string
    {
        $locale = app()->getLocale();

        return $this->{"title_{$locale}"} ?: $this->title_gu;
    }

    /**
     * Optional caption shared alongside the generated status image. Falls
     * back to Gujarati; returns '' when the admin left every language
     * blank, in which case nothing is attached to the share.
     */
    public function getShareTextAttribute(): string
    {
        $locale = app()->getLocale();

        return $this->{"share_text_{$locale}"} ?: (string) $this->share_text_gu;
    }
}
