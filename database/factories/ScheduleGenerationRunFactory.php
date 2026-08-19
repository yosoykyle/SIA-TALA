<?php

namespace Database\Factories;

use App\Models\ScheduleGenerationRun;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScheduleGenerationRun>
 */
class ScheduleGenerationRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'status' => ScheduleGenerationRun::StatusPublished,
            'input_snapshot' => ['contract_version' => ScheduleGenerationRun::ContractVersion],
            'input_hash' => hash('sha256', fake()->unique()->uuid()),
            'contract_version' => ScheduleGenerationRun::ContractVersion,
            'solver_version' => ScheduleGenerationRun::SolverVersion,
            'quality_policy' => ScheduleGenerationRun::QualityPolicyLexicographic,
            'candidate_state' => 'Accepted',
            'requested_by' => User::factory(),
            'published_by' => User::factory(),
            'published_at' => now(),
            'publication_version' => 1,
        ];
    }
}
