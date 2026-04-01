<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasUuid;

    protected $table = 'temple_payments';

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
}
