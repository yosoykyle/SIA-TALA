<?php

namespace Database\Factories;

use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Term>
 */
class TermFactory extends Factory
{
    public function definition(): array
    {
        return [
            'term_name' => 'Term '.fake()->unique()->numberBetween(1000, 9999),
            'term_type' => 'semester',
            'is_active' => true,
            'term_start_date' => now()->startOfMonth()->toDateString(),
            'term_end_date' => now()->addMonths(4)->endOfMonth()->toDateString(),
            'class_start_date' => now()->addWeek()->toDateString(),
            'class_end_date' => now()->addMonths(4)->toDateString(),
            'scheduling_starts_at' => now()->subWeek(),
            'enrollment_starts_at' => now()->subDays(3),
            'enrollment_ends_at' => now()->addDays(14),
            'payment_deadline' => now()->addDays(21),
            'adjustment_ends_at' => now()->addDays(14),
        ];
    }
}
