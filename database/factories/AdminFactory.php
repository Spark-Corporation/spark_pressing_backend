<?php

namespace Database\Factories;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fullname' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'status' => true,
        ];
    }
}
