<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\DonationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class CreateDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `donation_type` is a legacy fixed enum (App\Enums\DonationType) that
     * lives in its own column, PARALLEL to the admin-managed
     * `donation_type_id`. The donate form sends the selected admin type's
     * *slug* as donation_type — which only coincidentally matches an enum
     * case for the original built-in types (general/seva/annadan/…). Any
     * admin-created type with a different slug (e.g. a birthday greeting-card
     * type, slug "birthday") would otherwise fail the `in:` rule with
     * "The selected donation type is invalid", and even if it slipped
     * through, the enum cast would throw when the model is read back.
     *
     * Coerce any slug that isn't a real enum case to `other`. The true type
     * is never lost — it's carried by donation_type_id and the donationType
     * relation, which is what the greeting-card / receipt logic keys off.
     */
    protected function prepareForValidation(): void
    {
        $type = $this->input('donation_type');

        if ($type !== null && DonationType::tryFrom((string) $type) === null) {
            $this->merge(['donation_type' => DonationType::OTHER->value]);
        }
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1'],
            'donation_type' => ['required', 'string', 'in:general,seva,annadan,construction,festival,campaign,other'],
            'donation_type_id' => ['nullable', 'integer', 'exists:temple_donation_types,id'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'campaign_id' => ['nullable', 'integer', 'exists:temple_donation_campaigns,id'],
            // A sub-cause, if given, must belong to the selected campaign.
            'sub_cause_id' => [
                'nullable',
                'integer',
                Rule::exists('temple_campaign_sub_causes', 'id')
                    ->where(fn ($q) => $q->where('campaign_id', $this->input('campaign_id'))),
            ],
            'anonymous' => ['nullable', 'boolean'],
            // Item 5.4 — the donor's REQUEST for a statutory 80G receipt.
            // Optional and defaulting to true so older app builds (which
            // send no such field) keep asking for one; whether they GET
            // one is decided by the PAN gate in ReceiptService, not here.
            'wants_80g' => ['nullable', 'boolean'],
        ] + \App\Support\ExtraFieldValues::rules();
    }
}
