<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SevaCategory;
use App\Services\SevaSlotService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'assignee_id',
        'notification_config',
        'linked_products',
    ];

    protected $casts = [
        'category' => SevaCategory::class,
        'price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'is_variable_price' => 'boolean',
        'slot_config' => 'array',
        'notification_config' => 'array',
        'linked_products' => 'array',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assignee_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(SevaBooking::class, 'seva_id');
    }

    public function hasProductSelection(): bool
    {
        $config = $this->linked_products;

        return ! empty($config) && ! empty($config['type']);
    }

    public function getLinkedProductsList(): \Illuminate\Database\Eloquent\Collection
    {
        $config = $this->linked_products;

        if (empty($config) || empty($config['type'])) {
            return Product::query()->where('id', 0)->get(); // empty collection
        }

        $query = Product::where('is_active', true);

        if ($config['type'] === 'products' && ! empty($config['product_ids'])) {
            $query->whereIn('id', $config['product_ids']);
        } elseif ($config['type'] === 'category' && ! empty($config['category_id'])) {
            $query->where('category_id', $config['category_id']);
        } else {
            return Product::query()->where('id', 0)->get();
        }

        return $query->orderBy('sort_order')->get();
    }

    public function getProductSelectionLabel(): string
    {
        $config = $this->linked_products;
        $locale = app()->getLocale();
        $key = "label_{$locale}";

        return $config[$key] ?? $config['label_gu'] ?? $config['label_en'] ?? 'વિકલ્પ પસંદ કરો';
    }

    public function getResolvedSlotConfig(): array
    {
        return app(SevaSlotService::class)->normalizeConfig($this->slot_config);
    }

    public function getSlotsForDate(string $date): array
    {
        return app(SevaSlotService::class)->getSlotsForDate($this, $date);
    }

    public function isDateBlackedOut(string $date): ?string
    {
        $config = $this->getResolvedSlotConfig();

        return app(SevaSlotService::class)->getBlackoutReason($config, $date);
    }

    public function isDateInAcceptancePeriod(string $date): bool
    {
        $config = $this->getResolvedSlotConfig();

        return app(SevaSlotService::class)->isDateInAcceptancePeriod($config, $date);
    }

    public function getMaxBookingsPerSlot(): int
    {
        return (int) ($this->getResolvedSlotConfig()['max_bookings_per_slot'] ?? 1);
    }

    public function getSlotDurationMinutes(): int
    {
        return (int) ($this->getResolvedSlotConfig()['slot_duration_minutes'] ?? 60);
    }
}
