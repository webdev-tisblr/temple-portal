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
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            // Accepts an HH:MM time slot OR a full-day/full-week mode sentinel
            // ('full_day' / 'full_week'). SevaSlotService validates the value
            // semantically against the seva's configured slots.
            'slot_time' => ['nullable', 'string', 'max:20'],
            'quantity' => ['integer', 'min:1', 'max:5'],
            'devotee_name_for_seva' => ['nullable', 'string', 'max:255'],
            'sankalp' => ['nullable', 'string', 'max:1000'],
            'selected_product_id' => ['nullable', 'integer', 'exists:temple_products,id'],
            'selected_variant_label' => ['nullable', 'string', 'max:255'],
        ] + \App\Support\ExtraFieldValues::rules();
    }
}
