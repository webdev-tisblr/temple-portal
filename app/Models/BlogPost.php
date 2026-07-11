<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PageStatus;
use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    use HasManagedImages;

    protected $table = 'temple_blog_posts';

    protected function managedImages(): array
    {
        return ['featured_image_path' => 'r2'];
    }

    protected $fillable = [
        'slug',
        'title_gu',
        'title_hi',
        'title_en',
        'body_gu',
        'body_hi',
        'body_en',
        'excerpt_gu',
        'excerpt_hi',
        'excerpt_en',
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

    public function getExcerptAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "excerpt_{$locale}";
        return $this->$field ?: $this->excerpt_gu;
    }
}
