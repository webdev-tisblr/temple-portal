<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $table = 'temple_products';

    protected $fillable = [
        'category_id',
        'name_gu',
        'name_hi',
        'name_en',
        'slug',
        'description_gu',
        'description_hi',
        'description_en',
        'price',
        'stock_quantity',
        'image_path',
        'is_active',
        'is_featured',
        'has_variants',
        'variants',
        'is_seva_only',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock_quantity' => 'integer',
        'is_active' => 'boolean',
        'is_featured' => 'boolean',
        'has_variants' => 'boolean',
        'is_seva_only' => 'boolean',
        'variants' => 'array',
        'sort_order' => 'integer',
    ];

    public function getNameAttribute(): string
    {
        $locale = app()->getLocale();
        $field = "name_{$locale}";
        return $this->$field ?? $this->name_gu;
    }

    public function getDescriptionAttribute(): ?string
    {
        $locale = app()->getLocale();
        $field = "description_{$locale}";
        return $this->$field ?? $this->description_gu;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }

    public function inStock(): bool
    {
        return $this->stock_quantity > 0;
    }

    public function getVariantPrice(string $label): ?float
    {
        if (! $this->has_variants || empty($this->variants)) {
            return null;
        }

        foreach ($this->variants as $v) {
            if (($v['label'] ?? '') === $label) {
                return (float) ($v['price'] ?? 0);
            }
        }

        return null;
    }

    public function getDisplayPrice(): string
    {
        if ($this->has_variants && ! empty($this->variants)) {
            $min = collect($this->variants)->min('price');
            return '₹' . number_format((float) $min, 2) . '+';
        }

        return '₹' . number_format((float) $this->price, 2);
    }

    public function decrementStock(int $qty): void
    {
        $this->decrement('stock_quantity', $qty);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeFeatured(Builder $q): Builder
    {
        return $q->where('is_featured', true);
    }

    public function scopeForStore(Builder $q): Builder
    {
        return $q->where('is_seva_only', false);
    }
}
