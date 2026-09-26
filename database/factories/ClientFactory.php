<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Client;
use App\Models\Pressing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pressing_id' => Pressing::factory(),
            'agency_id' => Agency::factory(),
            'fullname' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone_number' => fake()->unique()->numerify('69######'),
            'password' => 'password',
            'status' => true,
        ];
    }
}
