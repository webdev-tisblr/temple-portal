<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DonationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => (float) $this->amount,
            'donation_type' => is_object($this->donation_type) && method_exists($this->donation_type, 'value')
                ? $this->donation_type->value
                : (string) $this->donation_type,
            'purpose' => $this->purpose,
            'financial_year' => $this->financial_year,
            'is_80g_eligible' => (bool) $this->is_80g_eligible,
            'pan_verified' => (bool) $this->pan_verified,
            'receipt_generated' => (bool) $this->receipt_generated,
            'anonymous' => (bool) $this->anonymous,
            'receipt' => $this->when($this->receipt_generated && $this->relationLoaded('receipt') && $this->receipt, function () {
                return [
                    'receipt_number' => $this->receipt->receipt_number,
                    'pdf_available' => !empty($this->receipt->pdf_path),
                    'generated_at' => $this->receipt->generated_at?->toISOString(),
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
