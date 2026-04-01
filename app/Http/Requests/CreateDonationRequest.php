<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'donation_type' => ['required', 'string', 'in:general,seva,annadan,construction,festival,campaign,other'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'campaign_id' => ['nullable', 'integer', 'exists:temple_donation_campaigns,id'],
            'anonymous' => ['nullable', 'boolean'],
        ];
    }
}
