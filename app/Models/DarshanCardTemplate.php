<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-designed daily-darshan share card template (one row per format).
 *
 * Columns are named greeting_card_template / greeting_card_config on
 * purpose so the existing drag-drop overlay editor blade works unchanged
 * (same trick as StatusTemplate). Backgrounds are per-language: the bare
 * column is Gujarati/default, hi/en fall back to it.
 */
class DarshanCardTemplate extends Model
{
    use HasManagedImages;

    public const FORMAT_STORY = 'story';

    public const FORMAT_SQUARE = 'square';

    protected $table = 'temple_darshan_card_templates';

    protected $fillable = [
        'format',
        'greeting_card_template',
        'greeting_card_template_hi',
        'greeting_card_template_en',
        'greeting_card_config',
        'is_active',
    ];

    protected $casts = [
        'greeting_card_config' => 'array',
        'is_active' => 'boolean',
    ];

    protected function managedImages(): array
    {
        return [
            'greeting_card_template' => 'r2',
            'greeting_card_template_hi' => 'r2',
            'greeting_card_template_en' => 'r2',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The active, fully-configured template for a format — or null,
     * which sends the caller to the drawn-design fallback.
     */
    public static function forFormat(string $format): ?self
    {
        $template = static::active()->where('format', $format)->first();

        if (! $template
            || ! $template->greeting_card_template
            || empty($template->greeting_card_config['overlays'])
        ) {
            return null;
        }

        return $template;
    }

    /**
     * Editor variable buttons (consumed by the greeting-card editor blade).
     */
    public static function editorVars(): array
    {
        return [
            ['key' => 'darshan_photo', 'label' => 'Darshan Photo', 'type' => 'image', 'auto' => true],
            ['key' => 'user_photo', 'label' => 'Devotee Photo', 'type' => 'image', 'auto' => true],
            ['key' => '_donor_name', 'label' => 'Devotee Name', 'type' => 'text', 'auto' => true],
            ['key' => '_caption', 'label' => 'Photo Caption', 'type' => 'text', 'auto' => true],
            ['key' => '_date', 'label' => 'Date', 'type' => 'text', 'auto' => true],
            ['key' => '_temple_name', 'label' => 'Temple Name', 'type' => 'text', 'auto' => true],
        ];
    }
}
