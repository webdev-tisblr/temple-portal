<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SevaCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Seva extends Model
{
    use SoftDeletes;

    protected $table = 'temple_sevas';

    protected $fillable = [
        'name_gu',
        'name_hi',
        'name_en',
        'description_gu',
        'description_hi',
        'description_en',
        'category',
        'price',
        'min_price',
        'is_variable_price',
        'image_path',
        'slot_config',
        'requires_booking',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'category' => SevaCategory::class,
        'price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'is_variable_price' => 'boolean',
        'slot_config' => 'array',
        'requires_booking' => 'boolean',
        'is_active' => 'boolean',
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

    public function bookings(): HasMany
    {
        return $this->hasMany(SevaBooking::class, 'seva_id');
    }
}
