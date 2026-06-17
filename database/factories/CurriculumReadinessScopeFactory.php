<?php

namespace Database\Factories;

use App\Models\Curriculum;
use App\Models\CurriculumReadinessScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurriculumReadinessScope>
 */
class CurriculumReadinessScopeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'curriculum_id' => Curriculum::factory(),
            'year_level' => '1st Year',
            'curriculum_period' => '1st Semester',
            'status' => CurriculumReadinessScope::StatusNeedsReview,
            'last_blockers' => null,
            'last_blocker_hash' => null,
            'last_transition_reason' => null,
        ];
    }

    public function ready(): self
    {
        return $this->state(fn (): array => [
            'status' => CurriculumReadinessScope::StatusReadyForScheduling,
            'last_transition_at' => now(),
        ]);
    }

    public function blocked(): self
    {
        return $this->state(fn (): array => [
            'status' => CurriculumReadinessScope::StatusBlocked,
            'last_blockers' => ['curriculum_scope_has_no_subject_demand'],
            'last_blocker_hash' => hash('sha256', json_encode(['curriculum_scope_has_no_subject_demand'])),
            'last_transition_at' => now(),
        ]);
    }
}
