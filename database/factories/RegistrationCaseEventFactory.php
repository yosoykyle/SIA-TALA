<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\RegistrationCaseEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationCaseEvent>
 */
class RegistrationCaseEventFactory extends Factory
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
            'sequence' => 1,
            'event_type' => 'CaseStarted',
            'from_outcome' => null,
            'to_outcome' => Enrollment::OutcomeInProgress,
            'reason' => 'Synthetic canonical Registration Case event.',
            'recorded_at' => now(),
        ];
    }
}
