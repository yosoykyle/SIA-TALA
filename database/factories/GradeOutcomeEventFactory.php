<?php

namespace Database\Factories;

use App\Models\GradeOutcomeEvent;
use App\Models\GradeRosterRow;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GradeOutcomeEvent>
 */
class GradeOutcomeEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'grade_roster_row_id' => GradeRosterRow::factory(),
            'event_type' => GradeOutcomeEvent::TypeInitialRelease,
            'previous_value' => null,
            'new_value' => 1.50,
            'previous_category' => null,
            'new_category' => 'Passing',
            'deadline' => null,
            'authority' => 'Registrar',
            'reason' => 'Factory generated grade outcome event.',
            'evidence_reference' => null,
            'recorded_by' => User::factory(),
        ];
    }
}
