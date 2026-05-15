<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validation rules — kept in lockstep with the API endpoint in
     * Api/V1/StoreController and the Flutter cart_screen client-side
     * validation. Changing one here means changing all three.
     *
     *   phone   → max 15 (E.164 ceiling: '+91 ' prefix + 12-digit local)
     *   address → max 500 (matches Flutter UX cap)
     *   pincode → max 6 (Indian Pincode is exactly 6; we're India-only)
     */
    public function rules(): array
    {
        return [
            'shipping_name' => ['required', 'string', 'max:255'],
            'shipping_phone' => ['required', 'string', 'max:15'],
            'shipping_address' => ['required', 'string', 'max:500'],
            'shipping_city' => ['required', 'string', 'max:100'],
            'shipping_state' => ['required', 'string', 'max:100'],
            'shipping_pincode' => ['required', 'string', 'max:6'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
