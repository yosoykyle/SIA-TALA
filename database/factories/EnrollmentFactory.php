<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'credential_user_id' => fn (array $attributes): int => (int) StudentProfile::query()->findOrFail($attributes['student_profile_id'])->user_id,
            'term_id' => Term::factory(),
            'case_reference' => fn (): string => 'REG-'.Str::upper((string) Str::ulid()),
            'selection_basis' => Enrollment::SelectionStandardCurriculum,
            'canonical_outcome' => Enrollment::OutcomeInProgress,
            'lock_version' => 0,
            'status' => 'pending_payment',
            'student_type' => null,
            'registered_at' => null,
            'officially_enrolled_at' => null,
            'cancelled_at' => null,
            'dropped_at' => null,
            'withdrawn_at' => null,
            'status_reason' => null,
        ];
    }
}
