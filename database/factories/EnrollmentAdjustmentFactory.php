<?php

namespace Database\Factories;

use App\Models\CorVersion;
use App\Models\Enrollment;
use App\Models\EnrollmentAdjustment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentAdjustment>
 */
class EnrollmentAdjustmentFactory extends Factory
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
            'supersedes_cor_version_id' => CorVersion::factory(),
            'authority_reference' => 'SYNTH-REGISTRATION-ADJUSTMENT',
            'financial_effect' => 'NoAdditionalCost',
            'change_snapshot' => ['source' => 'synthetic-canonical-factory'],
            'recorded_by' => User::factory(),
            'recorded_at' => now(),
        ];
    }
}
