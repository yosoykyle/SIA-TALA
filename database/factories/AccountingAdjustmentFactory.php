<?php

namespace Database\Factories;

use App\Models\AccountingAdjustment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingAdjustment>
 */
class AccountingAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'adjustment_type' => AccountingAdjustment::TypeStudentAccountDebit,
            'amount' => '100.00',
            'reason' => 'Accounting correction with supporting evidence.',
            'posted_at' => now(),
            'posted_by' => User::factory(),
        ];
    }
}
