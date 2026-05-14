<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt80G extends Model
{
    use HasManagedImages;

    protected $table = 'temple_receipts_80g';

    protected function managedImages(): array
    {
        return ['pdf_path' => 'r2_private'];
    }

    /**
     * Surface `fiscal_year` as a virtual attribute aliasing `financial_year`.
     * NotificationRegistry advertises the placeholder as `receipt.fiscal_year`
     * (common Indian accounting term), but the actual DB column is named
     * `financial_year`. The Generate80GReceipt job dispatches receipt via
     * toArray(), so an accessor alone won't appear unless explicitly
     * $appended — without this, every donation 80G WhatsApp template
     * resolves fiscal_year to an empty string and Meta rejects the send
     * with (#131008) Required parameter is missing.
     */
    protected $appends = ['fiscal_year'];

    public function getFiscalYearAttribute(): ?string
    {
        return $this->financial_year;
    }

    protected $fillable = [
        'donation_id',
        'receipt_number',
        'financial_year',
        'devotee_name',
        'devotee_address',
        'devotee_phone',
        'devotee_email',
        'pan_number',
        'amount',
        'amount_in_words',
        'donation_date',
        'payment_mode',
        'trust_name',
        'trust_address',
        'trust_pan',
        'trust_80g_registration_no',
        'trust_80g_validity_period',
        'pdf_path',
        'generated_at',
        'emailed_at',
        'whatsapp_sent_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'donation_date' => 'date',
        'generated_at' => 'datetime',
        'emailed_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
    ];

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class, 'donation_id');
    }
}
