<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventType;
use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasManagedImages;

    protected $table = 'temple_events';

    public function media(): HasMany
    {
        return $this->hasMany(EventMedia::class, 'event_id')->orderBy('sort_order')->orderBy('id');
    }

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

    protected $fillable = [
        'title_gu',
        'title_hi',
        'title_en',
        'description_gu',
        'description_hi',
        'description_en',
        'start_date',
        'end_date',
        'start_time',
        'end_time',
        'location',
        'image_path',
        'is_featured',
        'status',
        'event_type',
        'created_by',
    ];

    protected $casts = [
        'event_type' => EventType::class,
        'start_date' => 'date',
        'end_date' => 'date',
        'is_featured' => 'boolean',
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
}
