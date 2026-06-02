<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'term_id' => Term::factory(),
            'status' => 'pending_payment',
            'student_type' => 'new',
            'year_level' => '1st Year',
            'modality' => 'on_site',
            'lis_status' => 'not_encoded',
            'is_late_enrollment' => false,
        ];
    }
}
