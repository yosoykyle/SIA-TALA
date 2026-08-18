<?php

namespace Database\Factories;

use App\Models\AdmissionCycle;
use App\Models\Term;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionCycle>
 */
class AdmissionCycleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $publicClose = now()->addMonth();

        return [
            'code' => 'CYCLE-'.fake()->unique()->numerify('####-####'),
            'label' => fake()->words(4, true),
            'term_id' => Term::factory(),
            'state' => AdmissionCycle::StateDraft,
            'opens_at' => now()->subDay(),
            'closes_at' => $publicClose,
            'correction_closes_at' => $publicClose,
            'applicant_instructions' => 'Complete the application and submit the listed preliminary evidence.',
            'support_contact' => 'Synthetic Registrar support',
            'privacy_notice_reference' => 'privacy-notice:synthetic-v1',
            'registrar_owner_id' => User::factory(),
        ];
    }

    public function published(): static
    {
        return $this->state(fn (): array => [
            'state' => AdmissionCycle::StatePublished,
        ]);
    }
}
