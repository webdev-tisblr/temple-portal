<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'temple_notifications';

    protected $fillable = [
        'title_gu',
        'title_hi',
        'title_en',
        'body_gu',
        'body_hi',
        'body_en',
        'image_url',
        'segment',
        'custom_filter',
        'scheduled_at',
        'sent_at',
        'total_recipients',
        'delivered_count',
        'opened_count',
        'status',
        'created_by',
    ];

    protected $casts = [
        'custom_filter' => 'array',
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'total_recipients' => 'integer',
        'delivered_count' => 'integer',
        'opened_count' => 'integer',
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
