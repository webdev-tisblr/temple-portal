<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Trustee extends Model
{
    use HasManagedImages;

    protected $table = 'temple_trustees';

    protected static function booted(): void
    {
        $bust = fn () => Cache::forget('content.trustees.v1');
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return ['photo_path' => 'r2'];
    }

    protected $fillable = [
        'name_gu',
        'name_hi',
        'name_en',
        'role_gu',
        'role_hi',
        'role_en',
        'location',
        'location_gu',
        'location_hi',
        'location_en',
        'photo_path',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getNameAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "name_{$locale}";

        return $this->$field ?: $this->name_gu;
    }

    public function getRoleAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "role_{$locale}";

        return $this->$field ?: $this->role_gu;
    }

    public function getLocationAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "location_{$locale}";

        // Fall back to the localized gu value, then the legacy column.
        return $this->$field ?: ($this->location_gu ?: ($this->attributes['location'] ?? null));
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
