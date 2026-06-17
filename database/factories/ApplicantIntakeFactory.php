<?php

namespace Database\Factories;

use App\Models\ApplicantIntake;
use App\Models\Program;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicantIntake>
 */
class ApplicantIntakeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state([
                'status' => User::StatusApplicantPending,
            ]),
            'term_id' => Term::factory(),
            'program_id' => Program::factory(),
            'lrn' => fake()->numerify('############'),
            'birthdate' => fake()->dateTimeBetween('-25 years', '-12 years')->format('Y-m-d'),
            'place_of_birth' => fake()->city(),
            'gender' => 'female',
            'civil_status' => 'single',
            'mothers_maiden_name' => fake()->name(),
            'contact_number' => '09'.fake()->numerify('#########'),
            'street' => fake()->streetAddress(),
            'barangay' => fake()->word(),
            'city' => fake()->city(),
            'province' => fake()->state(),
            'region' => 'Region IV-A',
            'zip_code' => fake()->postcode(),
            'father_name' => fake()->name('male'),
            'father_occupation' => fake()->jobTitle(),
            'mother_occupation' => fake()->jobTitle(),
            'education_level' => 'college',
            'year_level' => '1st Year',
            'applicant_type' => ApplicantIntake::ApplicantTypeNew,
            'preferred_modality' => 'online',
            'orientation_modality_acknowledged_at' => now(),
            'orientation_policy_accepted_at' => now(),
            'status' => ApplicantIntake::StatusPending,
            'duplicate_check_status' => ApplicantIntake::DuplicateStatusClear,
            'duplicate_check_payload' => ['matches' => []],
            'required_documents' => [
                'psa_birth_certificate',
                'grade_11_card',
                'grade_12_card',
                'form_137',
                'good_moral',
                'diploma',
            ],
        ];
    }
}
