<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receipt80G extends Model
{
    protected $table = 'temple_receipts_80g';

    protected $fillable = [
        'donation_id',
        'receipt_number',
        'financial_year',
        'devotee_name',
        'devotee_address',
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
