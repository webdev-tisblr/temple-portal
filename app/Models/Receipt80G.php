<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasManagedImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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

    /** Receipts issued for a seva booking rather than a direct donation. */
    public const SOURCE_DONATION = 'donation';

    public const SOURCE_SEVA = 'seva';

    protected $fillable = [
        // Exactly ONE of these two is ever set — see the
        // 2026_08_31_100100 migration. `source_type` records which,
        // so nothing has to branch on "which FK is null".
        'donation_id',
        'seva_booking_id',
        'source_type',
        'receipt_number',
        'financial_year',
        // Snapshots frozen at issue time — see ReceiptService. NOT resolved
        // live, or renaming a campaign would rewrite issued receipts.
        'campaign_title',
        'donation_purpose',
        // Seva particulars, frozen at issue time for the same reason
        // campaign_title is.
        'seva_name',
        'seva_date',
        'seva_slot_label',
        'seva_in_name_of',
        'quantity',
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
        'seva_date' => 'date',
        'generated_at' => 'datetime',
        'emailed_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
    ];

    public function donation(): BelongsTo
    {
        return $this->belongsTo(Donation::class, 'donation_id');
    }

    public function sevaBooking(): BelongsTo
    {
        return $this->belongsTo(SevaBooking::class, 'seva_booking_id');
    }

    public function isForSeva(): bool
    {
        return $this->source_type === self::SOURCE_SEVA;
    }

    /**
     * The date the receipt is "for" — a seva receipt is dated by the seva,
     * a donation receipt by the donation. Both live in their own column so
     * neither statutory document has to explain a blank field.
     */
    public function getIssuedForDateAttribute(): ?Carbon
    {
        return $this->isForSeva() ? $this->seva_date : $this->donation_date;
    }

    /**
     * One-line description of what was paid for, for list views.
     */
    public function getSourceLabelAttribute(): ?string
    {
        return $this->isForSeva()
            ? $this->seva_name
            : ($this->campaign_title ?: $this->donation_purpose);
    }
}
