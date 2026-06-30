<?php

namespace Database\Factories;

use App\Models\CourseEnrollment;
use App\Models\GradeRoster;
use App\Models\GradeRosterRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeRosterRow>
 */
class GradeRosterRowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grade_roster_id' => GradeRoster::factory(),
            'course_enrollment_id' => CourseEnrollment::query()->inRandomOrder()->value('id') ?? 1,
            'prelim_equivalent' => null,
            'midterm_equivalent' => null,
            'final_equivalent' => null,
            'computed_average' => null,
            'current_outcome_code' => null,
            'current_outcome_category' => null,
            'released_at' => null,
        ];
    }
}
