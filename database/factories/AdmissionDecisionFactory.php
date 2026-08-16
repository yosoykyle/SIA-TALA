<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\AdmissionDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionDecision>
 */
class AdmissionDecisionFactory extends Factory
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
            'decision' => AdmissionDecision::DecisionAdmitted,
            'reason' => 'The application met the synthetic admission review criteria.',
            'authority_reference' => 'Synthetic Registrar admission authority',
            'applicant_explanation' => 'Your application was admitted. Complete the remaining official credentials.',
            'decided_by' => User::factory(),
            'decided_at' => now(),
            'supersedes_admission_decision_id' => null,
        ];
    }

    public function admitted(): static
    {
        return $this->state(fn (): array => [
            'decision' => AdmissionDecision::DecisionAdmitted,
        ]);
    }

    public function notAdmitted(): static
    {
        return $this->state(fn (): array => [
            'decision' => AdmissionDecision::DecisionNotAdmitted,
            'applicant_explanation' => 'Your application was not admitted for this cycle.',
        ]);
    }
}
