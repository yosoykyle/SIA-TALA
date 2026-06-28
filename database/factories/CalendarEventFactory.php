<?php

namespace Database\Factories;

use App\Models\CalendarEvent;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startAt = now()->addDays(fake()->numberBetween(5, 20))->setTime(8, 0);

        return [
            'term_id' => Term::factory(),
            'event_type' => CalendarEvent::TypeWindow,
            'scope_type' => CalendarEvent::ScopeInstitution,
            'room_id' => null,
            'faculty_user_id' => null,
            'process_key' => 'term_planning',
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addDays(5),
            'day_of_week' => null,
            'starts_at' => null,
            'ends_at' => null,
            'blocks_scheduling' => false,
            'state' => CalendarEvent::StateActive,
            'authority' => 'Registrar Office',
        ];
    }
}
