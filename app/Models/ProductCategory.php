<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class ProductCategory extends Model
{
    use HasManagedImages;

    protected $table = 'temple_product_categories';

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

    protected static function booted(): void
    {
        $bust = static function () {
            Cache::forget('store.categories');
            Cache::forget('store_categories_with_counts');
            Cache::forget('store_featured_products');
        };
        static::saved($bust);
        static::deleted($bust);
        static::restored($bust);
    }

    protected $fillable = [
        'name_gu',
        'name_hi',
        'name_en',
        'slug',
        'description',
        'image_path',
        'sort_order',
        'is_active',
        'is_seva_only',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_seva_only' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getNameAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "name_{$locale}";
        return $this->$field ?? $this->name_gu;
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }
}
