<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\EnrollmentSubject;
use App\Models\Grade;
use App\Models\GradeSubmissionPackage;
use App\Models\GradeSubmissionPackageItem;
use App\Models\StudentProfile;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeSubmissionPackageItem>
 */
class GradeSubmissionPackageItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'grade_submission_package_id' => GradeSubmissionPackage::factory(),
            'enrollment_subject_id' => EnrollmentSubject::factory(),
            'grade_id' => Grade::factory(),
            'enrollment_id' => Enrollment::factory(),
            'student_profile_id' => StudentProfile::factory(),
            'subject_id' => Subject::factory(),
            'entered_values' => [
                'prelim_grade' => '90.00',
                'midterm_grade' => '90.00',
                'final_grade' => '90.00',
            ],
            'derived_grade' => [
                'grade' => '1.50',
                'remarks' => 'passed',
                'is_inc' => false,
                'inc_expires_at' => null,
            ],
            'remarks' => 'passed',
        ];
    }
}
