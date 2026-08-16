<?php

namespace Database\Factories;

use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirementSet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionRequirementSet>
 */
class AdmissionRequirementSetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_cycle_id' => AdmissionCycle::factory(),
            'application_path' => AdmissionCycle::PathFirstYear,
            'version' => 1,
            'state' => AdmissionRequirementSet::StateDraft,
            'authority_reference' => 'Synthetic Registrar authority',
            'effective_at' => null,
            'published_by' => null,
            'published_at' => null,
            'replaces_requirement_set_id' => null,
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'state' => AdmissionRequirementSet::StatePublished,
            'effective_at' => now(),
            'published_by' => User::factory(),
            'published_at' => now(),
        ]);
    }
}
