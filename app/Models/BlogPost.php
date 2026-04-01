<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlogPost extends Model
{
    use SoftDeletes;

    protected $table = 'temple_blog_posts';

    protected $fillable = [
        'slug',
        'title_gu',
        'title_hi',
        'title_en',
        'body_gu',
        'body_hi',
        'body_en',
        'excerpt_gu',
        'featured_image_path',
        'meta_title',
        'meta_description',
        'category',
        'status',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'status' => PageStatus::class,
        'published_at' => 'datetime',
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
