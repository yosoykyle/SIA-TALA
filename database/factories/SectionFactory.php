<?php

namespace Database\Factories;

use App\Models\Section;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Section>
 */
class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'term_offering_id' => TermOffering::factory(),
            'code' => fake()->unique()->bothify('BSIT-1?'),
            'capacity' => 30,
            'state' => Section::StatePlanned,
        ];
    }
}
