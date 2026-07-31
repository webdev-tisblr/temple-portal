<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\ValidPhoneNumber;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone)) {
            // Canonicalise before validation so OtpService and Devotee
            // lookups always see one exact form per number.
            $this->merge(['phone' => PhoneNumber::normalize($phone) ?? $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new ValidPhoneNumber],
        ];
    }
}
