<?php

namespace Database\Factories;

use App\Models\Program;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentProfile>
 */
class StudentProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'student_id' => 'SIA-'.fake()->unique()->numerify('######'),
            'lrn' => fake()->optional()->numerify('############'),
            'education_level' => 'college',
            'program_id' => Program::factory(),
            'year_level' => '1st Year',
            'operational_status' => 'Active',
            'modality' => 'on_site',
            'current_balance' => '0.00',
            'hard_copy_received' => false,
        ];
    }
}
