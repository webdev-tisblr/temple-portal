<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    use HasManagedImages;

    protected $table = 'temple_product_images';

    protected $fillable = [
        'product_id',
        'image_path',
        'sort_order',
    ];

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
