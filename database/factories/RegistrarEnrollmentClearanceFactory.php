<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrarEnrollmentClearance>
 */
class RegistrarEnrollmentClearanceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'result' => RegistrarEnrollmentClearance::ResultActionNeeded,
            'external_checks_confirmed' => false,
            'safe_instruction' => 'Contact the Registrar about the external school checks.',
            'reason' => null,
            'authority_reference' => 'Synthetic Registrar authority',
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
            'supersedes_clearance_id' => null,
        ];
    }

    public function forAdmittedApplication(AdmissionApplication $application): static
    {
        return $this->state(fn (): array => [
            'admission_application_id' => $application->id,
            'application_submission_version_id' => $application->current_submission_version_id,
            'admission_decision_id' => $application->decisions()->whereDoesntHave('successor')->firstOrFail()->id,
            'identity_source_hash' => RegistrarEnrollmentClearance::identitySourceHash($application),
        ]);
    }
}
