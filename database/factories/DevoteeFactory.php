<?php

namespace Database\Factories;

use App\Models\Devotee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Devotee>
 */
class DevoteeFactory extends Factory
{
    protected $model = Devotee::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            // Unique numeric phone within the 10-digit column width.
            'phone' => (string) fake()->unique()->numerify('9#########'),
            'email' => fake()->unique()->safeEmail(),
            'state' => 'Gujarat',
            'country' => 'India',
            'language' => 'gu',
            'is_active' => true,
        ];
    }

    /**
     * A devotee who can actually be issued an 80G receipt (item 5.4).
     *
     * The strict rule is "no readable, format-valid PAN → no receipt and
     * no receipt number", so the default factory devotee — who has no PAN
     * — is deliberately INELIGIBLE. Any test that expects a Receipt80G row
     * must opt in here.
     */
    public function withPan(string $pan = 'ABCDE1234F'): static
    {
        $pan = strtoupper($pan);

        return $this->state(fn (array $attributes) => [
            'pan_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString($pan),
            'pan_last_four' => substr($pan, -4),
        ]);
    }
}
