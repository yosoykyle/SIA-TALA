<?php

namespace Database\Factories;

use App\Actions\Completion\CompletionReadinessProjection;
use App\Models\CompletionReadinessVersion;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompletionReadinessVersion>
 */
class CompletionReadinessVersionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'version' => 1,
            'state' => CompletionReadinessProjection::NotEligible,
            'source_fingerprint' => hash('sha256', fake()->uuid()),
            'source_snapshot' => [],
            'blockers' => [],
            'generated_at' => now(),
        ];
    }
}
