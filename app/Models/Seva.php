<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasExtraFields;
use App\Models\Concerns\HasManagedImages;
use App\Services\SevaSlotService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Seva extends Model
{
    use HasExtraFields;
    use HasManagedImages;

    protected $table = 'temple_sevas';

    /**
     * Which image the seva's booking/reminder messages carry — the value
     * behind the single `{{ image_url }}` placeholder. Each one is a
     * preference at the head of a fallback chain, never a hard branch; see
     * SevaBookingContext::resolveImageUrl().
     */
    public const IMAGE_SOURCE_PRODUCT = 'product';

    public const IMAGE_SOURCE_SEVA = 'seva';

    public const IMAGE_SOURCE_NONE = 'none';

    /** @return array<string, string> */
    public static function imageSourceOptions(): array
    {
        return [
            self::IMAGE_SOURCE_PRODUCT => 'Chosen product image (falls back to the seva image)',
            self::IMAGE_SOURCE_SEVA => 'Seva featured image (falls back to the product image)',
            self::IMAGE_SOURCE_NONE => 'No image',
        ];
    }

    protected function managedImages(): array
    {
        return [
            'image_path' => 'r2',
            'greeting_card_template' => 'r2',
            'greeting_card_template_hi' => 'r2',
            'greeting_card_template_en' => 'r2',
        ];
    }

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
        'extra_fields',
        'slot_pool_id',
        'requires_booking',
        'is_active',
        'sort_order',
        'assignee_id',
        'reminder_offsets',
        'reminder_mode',
        'send_darshan_on_booking_date',
        'linked_products',
        'notification_image_source',
        'greeting_card_template',
        'greeting_card_template_hi',
        'greeting_card_template_en',
        'greeting_card_config',
    ];

    protected $casts = [
        // 'category' is a plain string slug referencing
        // temple_seva_categories.slug (admin-managed since 2026-08-04).
        'price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'is_variable_price' => 'boolean',
        'slot_config' => 'array',
        'extra_fields' => 'array',
        'reminder_offsets' => 'array',
        'linked_products' => 'array',
        'greeting_card_config' => 'array',
        'requires_booking' => 'boolean',
        'send_darshan_on_booking_date' => 'boolean',
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

    /** Shared capacity pool — when set, slot settings come from the pool. */
    public function slotPool(): BelongsTo
    {
        return $this->belongsTo(SevaSlotPool::class, 'slot_pool_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(SevaBooking::class, 'seva_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(SevaMedia::class, 'seva_id')->orderBy('sort_order')->orderBy('id');
    }

    /** Custom reminder rules for this seva (used when reminder_mode=custom). */
    public function reminderRules(): HasMany
    {
        return $this->hasMany(SevaReminderRule::class, 'seva_id')->orderBy('sort_order')->orderBy('id');
    }

    public function hasProductSelection(): bool
    {
        $config = $this->linked_products;

        return ! empty($config) && ! empty($config['type']);
    }

    /**
     * The products a devotee may pick from for this seva.
     *
     * Sold-out products are dropped here (2026-08-17) rather than in each
     * view, so every surface agrees: the website tiles, the app's
     * product_selection payload, and the booking validation on both the web
     * and API paths all read this one list. A product with zero stock is not
     * offerable, so it must not be selectable either — filtering only at the
     * display layer would leave the POST/booking endpoints happy to accept it.
     *
     * Stock lives partly in the variants JSON, so the filter runs in PHP via
     * Product::inStock() (untracked products count as always available; a
     * variable product survives while ANY variant has stock, and the sold-out
     * variants stay visible-but-disabled as they are in the store).
     */
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

        return $query->orderBy('sort_order')
            ->get()
            ->filter(fn (Product $product) => $product->inStock())
            ->values();
    }

    /**
     * The "Starting ₹X" price for a seva whose price is driven by a product
     * choice, or null when the seva's own price applies.
     *
     * Lifted out of SevaResource (2026-08-17) so the website renders the same
     * number as the app instead of showing the seva's own price, which for
     * these sevas is not what anyone actually pays.
     *
     * Only purchasable options count: sold-out products are already absent
     * from getLinkedProductsList(), and a sold-out VARIANT is skipped here
     * too — it stays visible on the page but advertising a starting price
     * nobody can buy would be a lie. A zero price means "this option does not
     * set the price", so those are excluded; when no option carries a price
     * the result is null and callers fall back to the seva's own price.
     */
    public function startsFromPrice(): ?float
    {
        if (! $this->hasProductSelection()) {
            return null;
        }

        $prices = $this->getLinkedProductsList()->flatMap(function (Product $product) {
            if ($product->has_variants && ! empty($product->variants)) {
                return collect($product->variants)
                    ->filter(fn ($v) => ! $product->track_stock || (int) ($v['stock'] ?? 0) > 0)
                    ->map(fn ($v) => (float) ($v['price'] ?? 0));
            }

            return [(float) $product->price];
        })->filter(fn (float $price) => $price > 0);

        return $prices->isEmpty() ? null : (float) $prices->min();
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
        // Pool members follow the POOL's slot settings, not their own.
        return app(SevaSlotService::class)->configFor($this);
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
