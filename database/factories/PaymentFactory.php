<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\StudentProfile;
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
            'enrollment_id' => Enrollment::factory(),
            'term_id' => null,
            'payment_reference' => 'PAY-'.fake()->unique()->numerify('######'),
            'channel' => 'cash',
            'amount' => '1000.00',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ];
    }
}
