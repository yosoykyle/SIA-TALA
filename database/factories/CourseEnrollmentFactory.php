<?php

namespace Database\Factories;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\PublishedTimetableVersion;
use App\Models\Section;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseEnrollment>
 */
class CourseEnrollmentFactory extends Factory
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
            'term_offering_id' => TermOffering::factory(),
            'section_id' => Section::factory(),
            'published_timetable_version_id' => PublishedTimetableVersion::factory(),
            'change_source' => 'SyntheticFactory',
            'effective_from' => now(),
            'is_current' => true,
            'status' => CourseEnrollment::StatusActive,
            'units_snapshot' => '3.00',
            'added_at' => now(),
        ];
    }
}
