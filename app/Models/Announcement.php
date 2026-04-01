<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Announcement extends Model
{
    use SoftDeletes;

    protected $table = 'temple_announcements';

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
