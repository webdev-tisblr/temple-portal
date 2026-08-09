<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use App\Models\Concerns\HasManagedImages;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Order extends Model
{
    use HasManagedImages, HasUuid, LogsActivity;

    /**
     * Money-path audit (item 6.1). Status carries the two stock-restoring
     * admin actions (`cancel_order`, `update_status`), so an unexplained
     * stock movement can be traced back to a person.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'total_amount', 'subtotal', 'shipping_charge'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('money');
    }

    protected $table = 'temple_orders';

    protected function managedImages(): array
    {
        return ['invoice_path' => 'r2_private'];
    }

    protected $fillable = [
        'order_number',
        'devotee_id',
        'payment_id',
        'subtotal',
        'shipping_charge',
        'total_amount',
        'status',
        'shipping_name',
        'shipping_phone',
        'shipping_address',
        'shipping_city',
        'shipping_state',
        'shipping_pincode',
        'notes',
        'invoice_path',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'subtotal' => 'decimal:2',
        'shipping_charge' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Order $order) {
            if (empty($order->order_number)) {
                $order->order_number = 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
            }
        });
    }

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }
}
