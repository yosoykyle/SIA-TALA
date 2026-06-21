<?php

namespace Database\Factories;

use App\Models\FeeTemplate;
use App\Models\Program;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeTemplate>
 */
class FeeTemplateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Standard '.fake()->unique()->numberBetween(1000, 9999),
            'program_id' => Program::factory(),
            'year_level' => '1st Year',
            'tuition_fee' => '20800.00',
            'laboratory_fee' => '500.00',
            'misc_fee' => '1500.00',
            'other_fee' => '1250.00',
            'minimum_downpayment_percentage' => '20.00',
            'is_active' => true,
        ];
    }
}
