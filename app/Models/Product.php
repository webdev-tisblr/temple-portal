<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasManagedImages;

    protected $table = 'temple_products';

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

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

    /**
     * Whether the product can be added to a cart right now.
     *
     * For variable products the answer is "yes if ANY variant has
     * stock > 0" — even if some variants are sold out we still want
     * the card to show as available so the user can pick a stocked
     * variant. The per-variant gate is enforced at add-to-cart time
     * via getVariantStock().
     */
    public function inStock(): bool
    {
        if ($this->has_variants && ! empty($this->variants)) {
            foreach ($this->variants as $v) {
                if ((int) ($v['stock'] ?? 0) > 0) {
                    return true;
                }
            }
            return false;
        }

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

    /**
     * Stock count for a single variant (variants[i].stock). Returns
     * null when the product has no variants OR the label doesn't
     * match any variant — caller can fall back to stock_quantity or
     * treat as zero, whichever makes sense locally.
     */
    public function getVariantStock(string $label): ?int
    {
        if (! $this->has_variants || empty($this->variants)) {
            return null;
        }

        foreach ($this->variants as $v) {
            if (($v['label'] ?? '') === $label) {
                return (int) ($v['stock'] ?? 0);
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

    /** Drop $qty from the top-level stock_quantity (non-variant flow). */
    public function decrementStock(int $qty): void
    {
        $this->decrement('stock_quantity', $qty);
    }

    /**
     * Drop $qty from variants[label].stock and persist the variants
     * JSON. Returns true on success, false when the label doesn't
     * exist in the variants array — caller should treat false as
     * "stock check failed, bail before charging the customer."
     */
    public function decrementVariantStock(string $label, int $qty): bool
    {
        if (! $this->has_variants || empty($this->variants)) {
            return false;
        }

        $variants = $this->variants;
        $found = false;
        foreach ($variants as $i => $v) {
            if (($v['label'] ?? '') !== $label) continue;
            $variants[$i]['stock'] = max(0, (int) ($v['stock'] ?? 0) - $qty);
            $found = true;
            break;
        }

        if (! $found) return false;

        // forceFill + save — assigning to ->variants alone doesn't trip
        // Eloquent's dirty tracking when the underlying array is the
        // same reference. Persist explicitly.
        $this->forceFill(['variants' => $variants])->save();
        return true;
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
        return $q->where('is_seva_only', false)
            ->where(function (Builder $inner) {
                $inner->whereNull('category_id')
                    ->orWhereHas('category', fn (Builder $c) => $c->where('is_seva_only', false));
            });
    }
}
