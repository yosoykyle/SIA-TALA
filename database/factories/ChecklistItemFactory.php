<?php

namespace Database\Factories;

use App\Models\ApplicantIntake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChecklistItem>
 */
class ChecklistItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => ApplicantIntake::class,
            'owner_id' => ApplicantIntake::factory(),
            'requirement_type' => $this->faker->word(),
            'blocking_level' => 'blocks_handover',
            'status' => 'pending',
            'evidence_method' => 'physical_copy',
        ];
    }
}
