<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirement;
use App\Models\OfficialCredentialResult;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficialCredentialResult>
 */
class OfficialCredentialResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_application_id' => AdmissionApplication::factory()->submitted(),
            'admission_requirement_id' => AdmissionRequirement::factory(),
            'result' => OfficialCredentialResult::ResultNotReceived,
            'source_reference' => null,
            'safe_explanation' => 'The official credential has not yet been recorded.',
            'authority_reference' => 'Synthetic Registrar credential authority',
            'exception_expires_at' => null,
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
            'supersedes_official_credential_result_id' => null,
        ];
    }

    public function verified(): static
    {
        return $this->state(fn (): array => [
            'result' => OfficialCredentialResult::ResultVerified,
            'source_reference' => 'SYNTHETIC-CREDENTIAL-'.fake()->numerify('######'),
            'safe_explanation' => 'The Registrar verified the official credential.',
        ]);
    }

    public function actionNeeded(): static
    {
        return $this->state(fn (): array => [
            'result' => OfficialCredentialResult::ResultActionNeeded,
            'safe_explanation' => 'Contact the Registrar about the official credential.',
        ]);
    }
}
