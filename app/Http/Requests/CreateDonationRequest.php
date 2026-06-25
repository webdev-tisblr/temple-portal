<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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
            'donation_type_id' => ['nullable', 'integer', 'exists:temple_donation_types,id'],
            'purpose' => ['nullable', 'string', 'max:500'],
            'campaign_id' => ['nullable', 'integer', 'exists:temple_donation_campaigns,id'],
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
