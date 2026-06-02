<?php

namespace Database\Factories;

use App\Models\Enrollment;
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
            'enrollment_id' => Enrollment::factory(),
            'term_id' => null,
            'entry_type' => 'assessment',
            'description' => 'Assessment principal',
            'amount' => '1000.00',
            'running_balance' => '1000.00',
            'posted_at' => now(),
        ];
    }
}
