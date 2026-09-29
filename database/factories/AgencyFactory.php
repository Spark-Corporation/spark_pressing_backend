<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Pressing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pressing_id' => Pressing::factory(),
            'name' => fake()->city(),
            'address' => fake()->address(),
            'country_code' => 'CM',
            'currency' => 'XAF',
            'code_prefix' => strtoupper(fake()->lexify('??')),
            'code_suffix' => 'SP',
            'status' => true,
        ];
    }
}
