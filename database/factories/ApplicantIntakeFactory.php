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
            'modality_preference' => ApplicantIntake::ModalityPreferenceFaceToFace,
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'extension_name' => null,
            'birth_date' => fake()->dateTimeBetween('-25 years', '-12 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['MALE', 'FEMALE']),
            'civil_status' => 'SINGLE',
            'birth_place' => 'Synthetic City, Laguna',
            'email' => fake()->unique()->safeEmail(),
            'phone' => '09'.fake()->numerify('#########'),
            'address_barangay' => 'Synthetic Barangay',
            'address_street' => fake()->streetAddress(),
            'address_city' => 'Synthetic City',
            'address_district' => null,
            'address_province' => 'Laguna',
            'prior_school' => fake()->company(),
            'guardian_name' => fake()->name(),
            'guardian_phone' => '09'.fake()->numerify('#########'),
            'guardian_address' => 'Synthetic Barangay, Synthetic City, Laguna',
            'identity_evidence_reference' => 'applicant-identity-documents/'.fake()->uuid().'.pdf',
            'draft_document_references' => null,
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
