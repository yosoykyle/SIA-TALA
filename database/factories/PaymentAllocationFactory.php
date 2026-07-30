<?php

namespace Database\Factories;

use App\Models\AssessmentLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'assessment_line_id' => AssessmentLine::query()->inRandomOrder()->value('id'),
            'amount' => '500.00',
        ];
    }
}
