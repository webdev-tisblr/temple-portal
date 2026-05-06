<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hall extends Model
{
    use HasManagedImages;

    protected $table = 'temple_halls';

    protected function managedImages(): array
    {
        return ['image_path' => 'r2'];
    }

    protected $fillable = [
        'name',
        'description',
        'capacity',
        'price_per_day',
        'price_per_half_day',
        'amenities',
        'rules',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'capacity' => 'integer',
        'price_per_day' => 'decimal:2',
        'price_per_half_day' => 'decimal:2',
        'amenities' => 'array',
        'is_active' => 'boolean',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(HallBooking::class, 'hall_id');
    }
}
