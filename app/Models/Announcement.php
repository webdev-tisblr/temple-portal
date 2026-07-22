<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;

class Announcement extends Model
{
    use HasManagedImages;

    protected $table = 'temple_announcements';

    protected static function booted(): void
    {
        // Bust the home ticker + API announcements list on any admin change.
        $bust = static function (): void {
            \Illuminate\Support\Facades\Cache::forget('home.announcement.v1');
            \App\Support\LocalizedCache::forget('announcements.active.v2');
        };
        static::saved($bust);
        static::deleted($bust);
    }

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

    protected $fillable = [
        'title_gu',
        'title_hi',
        'title_en',
        'body_gu',
        'body_hi',
        'body_en',
        'image_path',
        'is_urgent',
        'published_at',
        'expires_at',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_urgent' => 'boolean',
        'is_active' => 'boolean',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function getTitleAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "title_{$locale}";
        return $this->$field ?? $this->title_gu;
    }

    public function getBodyAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "body_{$locale}";
        return $this->$field ?? $this->body_gu;
    }
}
