<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\RegistrationIdentityConfirmationVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationIdentityConfirmationVersion>
 */
class RegistrationIdentityConfirmationVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'supersedes_version_id' => null,
            'version' => 1,
            'admission_application_id' => AdmissionApplication::factory(),
            'source_version' => 'synthetic-v1',
            'source_hash' => hash('sha256', 'synthetic-registration-identity'),
            'identity_snapshot' => ['first_name' => 'Synthetic', 'last_name' => 'Student'],
            'confirmed_by' => User::factory(),
            'confirmed_at' => now(),
        ];
    }
}
