<?php

namespace Database\Factories;

use App\Models\Pressing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pressing>
 */
class PressingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Pressing',
            'details' => fake()->sentence(),
            'status' => true,
            'pricing_mode' => 'piece',
            'workflow_laveur_enabled' => true,
            'workflow_classeur_enabled' => true,
            'block_retrieve_if_unpaid' => true,
        ];
    }
}
