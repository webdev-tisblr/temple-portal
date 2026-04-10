<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DonationType as DonationTypeEnum;
use App\Models\DonationType as DonationTypeModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Donation extends Model
{
    use HasUuid;

    protected $table = 'temple_donations';

    protected $fillable = [
        'devotee_id',
        'payment_id',
        'amount',
        'donation_type',
        'donation_type_id',
        'purpose',
        'campaign_id',
        'seva_booking_id',
        'is_80g_eligible',
        'pan_verified',
        'pan_number_encrypted',
        'receipt_id',
        'receipt_generated',
        'anonymous',
        'notes',
        'extra_data',
        'greeting_card_path',
        'financial_year',
    ];

    protected $casts = [
        'donation_type' => DonationTypeEnum::class,
        'amount' => 'decimal:2',
        'is_80g_eligible' => 'boolean',
        'pan_verified' => 'boolean',
        'receipt_generated' => 'boolean',
        'anonymous' => 'boolean',
        'extra_data' => 'array',
    ];

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(DonationCampaign::class, 'campaign_id');
    }

    public function sevaBooking(): BelongsTo
    {
        return $this->belongsTo(SevaBooking::class, 'seva_booking_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt80G::class, 'donation_id');
    }

    public function donationType(): BelongsTo
    {
        return $this->belongsTo(DonationTypeModel::class, 'donation_type_id');
    }
}
