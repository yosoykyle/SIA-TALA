<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationSubmissionVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationSubmissionVersion>
 */
class ApplicationSubmissionVersionFactory extends Factory
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
            'admission_requirement_set_id' => AdmissionRequirementSet::factory()->published(),
            'version' => 1,
            'snapshot' => [
                'application_path' => AdmissionApplication::PathFirstYear,
                'privacy_acknowledged' => true,
                'accuracy_declared' => true,
            ],
            'privacy_notice_reference' => 'privacy-notice:synthetic-v1',
            'submitted_by' => User::factory(),
            'submitted_at' => now(),
        ];
    }
}
