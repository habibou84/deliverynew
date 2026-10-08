<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PricingGrid>
 */
class PricingGridFactory extends Factory
{
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => 'Grille '.fake()->word(),
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn () => ['name' => 'Grille standard', 'is_default' => true]);
    }
}
