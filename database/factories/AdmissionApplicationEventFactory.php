<?php

namespace Database\Factories;

use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionApplicationEvent>
 */
class AdmissionApplicationEventFactory extends Factory
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
            'event_type' => AdmissionApplicationEvent::TypeSubmitted,
            'event_key' => 'application-event:'.fake()->unique()->uuid(),
            'actor_id' => null,
            'source_type' => null,
            'source_id' => null,
            'payload' => ['state' => AdmissionApplication::StateSubmitted],
            'occurred_at' => now(),
        ];
    }
}
