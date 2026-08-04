<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GalleryCategory extends Model
{
    protected $table = 'temple_gallery_categories';

    protected $fillable = [
        'slug',
        'name_gu',
        'name_hi',
        'name_en',
        'sort_order',
    ];

    protected static function booted(): void
    {
        // Category names feed the localized web tabs and the app's chip
        // list endpoint — bust those caches whenever the list changes.
        $bust = function (): void {
            \App\Support\LocalizedCache::forget('gallery.categories');
        };
        static::saved($bust);
        static::deleted($bust);
    }

    public function getNameAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "name_{$locale}";

        return $this->$field ?? $this->name_gu;
    }

    public function images()
    {
        return $this->hasMany(GalleryImage::class, 'category', 'slug');
    }
}
