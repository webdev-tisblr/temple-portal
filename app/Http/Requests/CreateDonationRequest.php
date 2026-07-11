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
            'extra_data' => ['nullable', 'array'],
            // extra_data is a mixed bag: DonationType.extra_fields can define
            // text/number/etc. inputs AND file (image) inputs, all keyed
            // dynamically. DonationWebController stores ANY uploaded file
            // under extra_data.{key} straight to R2, so the file inputs must
            // be constrained to images. We can't enumerate the keys here, so
            // the per-item rule applies the image constraint ONLY when the
            // value is an actual uploaded file, leaving scalar text values
            // (which are validated as nullable) untouched.
            'extra_data.*' => [
                'nullable',
                function (string $attribute, $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $validator = validator(
                        [$attribute => $value],
                        [$attribute => [
                            'file',
                            'image',
                            'mimes:jpg,jpeg,png,webp',
                            'max:4096',
                        ]],
                    );

                    if ($validator->fails()) {
                        $fail('The uploaded file must be a JPG, PNG, or WEBP image under 4 MB.');
                    }
                },
            ],
        ];
    }
}
