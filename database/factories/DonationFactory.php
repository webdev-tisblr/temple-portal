<?php

namespace Database\Factories;

use App\Models\Devotee;
use App\Models\Donation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Donation>
 */
class DonationFactory extends Factory
{
    protected $model = Donation::class;

    public function definition(): array
    {
        return [
            'devotee_id' => DevoteeFactory::new(),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'donation_type' => 'general',
            // Mirrors the column default: the donor asked for a receipt.
            // Whether they GET one is decided by the PAN gate — see
            // DevoteeFactory::withPan() and tests/Feature/Strict80GTest.
            'wants_80g' => true,
            'is_80g_eligible' => true,
            'receipt_generated' => false,
            'anonymous' => false,
            // financial_year is NOT NULL on the table; "YYYY-YY" form.
            'financial_year' => '2026-27',
        ];
    }

    public function forDevotee(Devotee $devotee): static
    {
        return $this->state(fn (array $attributes) => [
            'devotee_id' => $devotee->id,
        ]);
    }
}
