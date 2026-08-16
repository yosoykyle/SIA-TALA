<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\ApplicantIntake;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionApplication>
 */
class AdmissionApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['status' => User::StatusActive]),
            'admission_cycle_id' => AdmissionCycle::factory(),
            'application_reference' => 'APP-'.fake()->unique()->numerify('########'),
            'application_state' => AdmissionApplication::StateDraft,
            'application_path' => AdmissionApplication::PathFirstYear,
            'term_id' => fn (array $attributes): int => AdmissionCycle::query()
                ->findOrFail($attributes['admission_cycle_id'])
                ->term_id,
            'program_id' => Program::factory(),
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'extension_name' => null,
            'birth_date' => fake()->dateTimeBetween('-25 years', '-18 years')->format('Y-m-d'),
            'citizenship_country_code' => 'PH',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('#########'),
            'current_city_municipality' => 'Synthetic City',
            'current_province' => 'Laguna',
            'prior_school_name' => fake()->company(),
            'prior_school_country_code' => 'PH',
            'prior_school_completion_year' => (int) now()->subYear()->format('Y'),
            'lrn' => null,
            'prior_college_identifier' => null,
            'guardian_full_name' => null,
            'guardian_relationship' => null,
            'guardian_mobile' => null,
            'privacy_notice_reference' => 'privacy-notice:synthetic-v1',
            'privacy_acknowledged_at' => now(),
            'accuracy_declared_at' => null,
            'current_submission_version_id' => null,
            'status' => ApplicantIntake::StatusDraft,
            'submitted_at' => null,
        ];
    }

    public function submitted(): static
    {
        return $this->state(fn (): array => [
            'application_state' => AdmissionApplication::StateSubmitted,
            'accuracy_declared_at' => now(),
            'submitted_at' => now(),
        ]);
    }

    public function transferee(): static
    {
        return $this->state(fn (): array => [
            'application_path' => AdmissionApplication::PathTransferee,
            'admission_category' => ApplicantIntake::AdmissionCategoryTransfer,
            'credential_basis' => ApplicantIntake::CredentialBasisTransferCredentials,
            'prior_college_identifier' => 'SYN-'.fake()->numerify('######'),
        ]);
    }
}
