<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Pressing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->name();

        return [
            'name' => $name,
            'fullname' => $name,
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => Str::random(10),
            'status' => true,
            'pressing_id' => Pressing::factory(),
            'agency_id' => Agency::factory(),
        ];
    }
}
