<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\ValidPhoneNumber;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phone = $this->input('phone');

        if (is_string($phone)) {
            $this->merge(['phone' => PhoneNumber::normalize($phone) ?? $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', new ValidPhoneNumber],
            'code' => ['required', 'string', 'size:6', 'regex:/^\d{6}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.size' => 'OTP must be exactly 6 digits.',
            'code.regex' => 'OTP must contain only digits.',
        ];
    }
}
