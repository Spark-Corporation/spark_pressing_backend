<?php

namespace Database\Factories;

use App\Models\Article;
use App\Models\Pressing;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Article>
 */
class ArticleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pressing_id' => Pressing::factory(),
            'name' => fake()->unique()->word(),
            'classic_price' => 1000,
            'express_price' => 1500,
            'repass_price' => 700,
            'status' => true,
        ];
    }
}
