<?php

namespace Database\Factories;

use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseDropRecord>
 */
class CourseDropRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'course_enrollment_id' => CourseEnrollment::factory(),
            'authority_reference' => 'SYNTH-COURSE-DROP',
            'reason' => 'Synthetic authorized Course Drop.',
            'finance_state' => 'AccountingReviewPending',
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
