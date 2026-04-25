<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DevoteeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'pincode' => $this->pincode,
            'country' => $this->country,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'language' => $this->language?->value ?? 'gu',
            'has_pan' => !empty($this->pan_encrypted),
            'pan_last_four' => $this->pan_last_four,
            'phone_verified' => !is_null($this->phone_verified_at),
            'profile_photo_url' => $this->profile_photo_path
                ? asset('storage/' . $this->profile_photo_path)
                : null,
            'donations_count' => $this->donations()->count(),
            'total_donated' => (int) $this->donations()
                ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
                ->sum('amount'),
            'bookings_count' => $this->sevaBookings()->count() + $this->hallBookings()->count(),
            'orders_count' => $this->orders()->count(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
