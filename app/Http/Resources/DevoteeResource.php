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
            // Signup verifies a phone, not a person — the row is created
            // with an empty name. The app gates its whole shell on this
            // rather than re-deriving "is name blank" in several places,
            // and the server refuses the transactional endpoints when it
            // is false (EnsureApiProfileComplete).
            'profile_complete' => $this->resource->hasCompleteProfile(),
            'profile_photo_url' => $this->profile_photo_path
                ? image_url($this->profile_photo_path)
                : null,
            'donations_count' => $this->donations()
                ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
                ->count(),
            'total_donated' => (int) $this->donations()
                ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
                ->sum('amount'),
            'bookings_count' => $this->sevaBookings()
                    ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
                    ->count()
                + $this->hallBookings()
                    ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
                    ->count(),
            'orders_count' => $this->orders()
                ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))
                ->count(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
