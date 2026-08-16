<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\ApplicationCorrectionRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApplicationCorrectionRequest>
 */
class ApplicationCorrectionRequestFactory extends Factory
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
            'sequence' => 1,
            'state' => ApplicationCorrectionRequest::StateActive,
            'applicant_instruction' => 'Replace the named evidence with a readable copy.',
            'responsible_party' => 'Applicant',
            'due_at' => now()->addWeek(),
            'requested_by' => User::factory(),
            'requested_at' => now(),
            'completed_at' => null,
            'supersedes_correction_request_id' => null,
        ];
    }
}
