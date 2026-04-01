<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Please enter a valid 10-digit Indian mobile number.',
        ];
    }
}
