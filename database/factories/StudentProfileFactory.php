<?php

namespace Database\Factories;

use App\Models\CurriculumVersion;
use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StudentProfile> */
class StudentProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'applicant_intake_id' => null,
            'student_number' => 'SIA-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-40 years', '-15 years')->format('Y-m-d'),
            'prior_identifier' => fake()->optional()->numerify('############'),
            'program_id' => Program::factory(),
            'curriculum_version_id' => fn (array $attributes) => CurriculumVersion::factory()->state([
                'program_id' => $attributes['program_id'],
                'state' => CurriculumVersion::StateActive,
            ]),
            'lifecycle_status' => StudentProfile::LifecycleActive,
            'academic_standing' => StudentProfile::StandingGood,
            'email' => fake()->safeEmail(),
            'phone' => '09'.fake()->numerify('#########'),
        ];
    }
}
