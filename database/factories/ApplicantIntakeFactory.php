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
            'admission_category' => ApplicantIntake::AdmissionCategoryFirstTimeCollege,
            'credential_basis' => ApplicantIntake::CredentialBasisSeniorHighSchool,
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'birth_date' => fake()->dateTimeBetween('-25 years', '-12 years')->format('Y-m-d'),
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('#########'),
            'prior_school' => fake()->company(),
            'identity_evidence_reference' => 'applicant-identity-documents/'.fake()->uuid().'.pdf',
            'status' => ApplicantIntake::StatusPending,
            'submitted_at' => now(),
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => ApplicantIntake::StatusDraft,
            'submitted_at' => null,
        ]);
    }

    public function approved(?User $actor = null): static
    {
        return $this->state(fn (): array => [
            'status' => ApplicantIntake::StatusApproved,
            'reviewed_at' => now(),
            'reviewed_by' => $actor?->id,
            'approved_at' => now(),
            'approved_by' => $actor?->id,
        ]);
    }
}
