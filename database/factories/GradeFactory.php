<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Grade;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grade>
 */
class GradeFactory extends Factory
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
            'enrollment_subject_id' => null,
            'subject_id' => Subject::factory(),
            'term_id' => Term::factory(),
            'faculty_id' => User::factory(),
            'prelim_grade' => '90.00',
            'midterm_grade' => '90.00',
            'final_grade' => '90.00',
            'grade' => '1.50',
            'remarks' => 'passed',
            'is_inc' => false,
            'inc_expires_at' => null,
            'is_finalized' => false,
        ];
    }
}
