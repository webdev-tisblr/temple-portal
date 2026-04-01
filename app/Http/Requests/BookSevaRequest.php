<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookSevaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_date' => ['required', 'date', 'after:today'],
            'slot_time' => ['nullable', 'string'],
            'quantity' => ['integer', 'min:1', 'max:5'],
            'devotee_name_for_seva' => ['nullable', 'string', 'max:255'],
            'gotra' => ['nullable', 'string', 'max:255'],
            'sankalp' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
