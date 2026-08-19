<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\ProgramAuthority;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgramAuthority>
 */
class ProgramAuthorityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'program_id' => Program::factory(),
            'authority_type' => 'Government recognition',
            'authority_reference' => 'SYNTH-AUTH-'.fake()->unique()->numerify('####'),
            'regulator' => 'Synthetic approving authority',
            'effective_from' => '2026-06-01',
            'effective_until' => null,
            'curriculum_source_reference' => 'SYNTH-CURRICULUM-2026',
            'state' => ProgramAuthority::StateDraft,
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
