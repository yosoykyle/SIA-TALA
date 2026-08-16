<?php

namespace Database\Factories;

use App\Models\AdmissionCycle;
use App\Models\AdmissionCycleEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionCycleEvent>
 */
class AdmissionCycleEventFactory extends Factory
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
            'event_type' => AdmissionCycleEvent::TypePublished,
            'event_key' => 'cycle-event:'.fake()->unique()->uuid(),
            'previous_values' => ['state' => AdmissionCycle::StateDraft],
            'new_values' => ['state' => AdmissionCycle::StatePublished],
            'reason' => 'The synthetic admission cycle passed readiness review.',
            'authority_reference' => 'Synthetic Registrar authority',
            'actor_id' => User::factory(),
            'occurred_at' => now(),
        ];
    }
}
