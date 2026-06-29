<?php

namespace Database\Factories;

use App\Models\LedgerEntry;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerEntry>
 */
class LedgerEntryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'term_id' => null,
            'enrollment_id' => null,
            'direction' => LedgerEntry::DirectionCharge,
            'category' => 'tuition',
            'amount' => '1000.00',
            'source_type' => StudentProfile::class,
            'source_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'description' => 'Assessment principal',
            'posted_at' => now(),
            'state' => 'posted',
        ];
    }
}
