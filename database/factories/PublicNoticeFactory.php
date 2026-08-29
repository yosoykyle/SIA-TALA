<?php

namespace Database\Factories;

use App\Models\PublicNotice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PublicNotice> */
class PublicNoticeFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => fake()->sentence(3), 'message' => fake()->sentence(), 'display_order' => fake()->unique()->numberBetween(1, 100000)];
    }
}
