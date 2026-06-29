<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\StudentProfile;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_profile_id' => StudentProfile::factory(),
            'term_id' => Term::factory(),
            'method' => 'cash',
            'channel' => 'cash',
            'amount' => '1000.00',
            'currency' => 'PHP',
            'evidence_status' => 'verified',
            'paid_at' => now(),
            'verified_at' => now(),
            'provider_reference' => 'PAY-'.fake()->unique()->numerify('######'),
        ];
    }
}
