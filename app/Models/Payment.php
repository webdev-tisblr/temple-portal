<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Payment extends Model
{
    use HasUuid;
    use LogsActivity;

    protected $table = 'temple_payments';

    /**
     * `razorpay_order_id` prefix for an offline/counter payment (item 6.1).
     *
     * The column is NOT NULL and UNIQUE, so a manual cash entry mints a
     * synthetic value rather than migrating a live money table. Prefixing
     * keeps offline money one `LIKE 'cash_%'` away in any report, and the
     * UNIQUE index doubles as the counter page's idempotency guard — the
     * same entry token can only ever insert one Payment.
     */
    public const OFFLINE_ORDER_PREFIX = 'cash_';

    /**
     * Payment methods a counter entry may record. Free-text `varchar(50)`
     * in the DB (no enum), so this list is the only definition.
     *
     * @var array<string,string>
     */
    public const OFFLINE_METHODS = [
        'cash' => 'Cash',
        'upi_offline' => 'UPI (scanned at counter)',
        'cheque' => 'Cheque',
        'bank_transfer' => 'Bank transfer / NEFT',
    ];

    protected $fillable = [
        'razorpay_order_id',
        'razorpay_payment_id',
        'razorpay_signature',
        'amount',
        'currency',
        'status',
        'method',
        'description',
        'webhook_payload',
        'refund_id',
        'refund_amount',
        'refunded_at',
        'paid_at',
        'created_by_admin_id',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'amount' => 'decimal:2',
        'refund_amount' => 'decimal:2',
        'webhook_payload' => 'array',
        'refunded_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function donation(): HasOne
    {
        return $this->hasOne(Donation::class, 'payment_id');
    }

    public function sevaBooking(): HasOne
    {
        return $this->hasOne(SevaBooking::class, 'payment_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class, 'payment_id');
    }

    public function hallBooking(): HasOne
    {
        return $this->hasOne(HallBooking::class, 'payment_id');
    }

    /** Which admin took this money at the counter (NULL for online). */
    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    /** True for a manual cash / offline counter entry (item 6.1). */
    public function isOffline(): bool
    {
        return str_starts_with((string) $this->razorpay_order_id, self::OFFLINE_ORDER_PREFIX);
    }

    /**
     * Forensic trail for the money path (item 6.1). Answers the questions
     * the `created_by_admin_id` column cannot: who later edited a status,
     * refunded, or re-captured. Spatie resolves the causer from the
     * authenticated user, so admin edits are attributed automatically.
     *
     * logOnlyDirty + dontSubmitEmptyLogs keep the volume down on a table
     * that every online payment also writes to.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'amount', 'method', 'paid_at', 'refund_amount', 'created_by_admin_id'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('money');
    }
}
