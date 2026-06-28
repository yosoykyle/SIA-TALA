<?php

namespace Database\Factories;

use App\Models\PersonalDataCorrectionRequest;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PersonalDataCorrectionRequest>
 */
class PersonalDataCorrectionRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'status' => PersonalDataCorrectionRequest::STATUS_PENDING,
            'requested_changes' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
            ],
            'old_values' => null,
            'resolved_by' => null,
            'resolved_at' => null,
            'reject_reason' => null,
        ];
    }
}
