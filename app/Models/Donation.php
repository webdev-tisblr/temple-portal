<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DonationType as DonationTypeEnum;
use App\Models\Concerns\HasManagedImages;
use App\Models\DonationType as DonationTypeModel;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Donation extends Model
{
    use HasManagedImages, HasUuid, LogsActivity;

    protected $table = 'temple_donations';

    protected function managedImages(): array
    {
        return ['greeting_card_path' => 'r2_private'];
    }

    protected $fillable = [
        'devotee_id',
        'payment_id',
        'amount',
        'donation_type',
        'donation_type_id',
        'purpose',
        'campaign_id',
        'sub_cause_id',
        'seva_booking_id',
        // wants_80g = the donor's request (checkout checkbox).
        // is_80g_eligible = the system's verdict under the strict PAN rule
        // (item 5.4). They are separate facts; do not conflate them.
        'wants_80g',
        'is_80g_eligible',
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
        'wants_80g' => 'boolean',
        'is_80g_eligible' => 'boolean',
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

    public function subCause(): BelongsTo
    {
        return $this->belongsTo(CampaignSubCause::class, 'sub_cause_id');
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

    /**
     * Money-path audit (item 6.1). `is_80g_eligible` / `anonymous` are the
     * two columns the strict-80G rule (item 5.4) rewrites behind the
     * donor's back, so both are logged — a "why did this become Gupt
     * Daan?" question has to be answerable months later.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['amount', 'donation_type', 'campaign_id', 'wants_80g', 'is_80g_eligible', 'anonymous', 'receipt_generated'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('money');
    }
}
