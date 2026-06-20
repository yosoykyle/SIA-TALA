<?php

namespace Database\Factories;

use App\Models\AdmissionCapacityPlan;
use App\Models\AdmissionCapacityReservation;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionCapacityReservation>
 */
class AdmissionCapacityReservationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'admission_capacity_plan_id' => AdmissionCapacityPlan::factory(),
            'enrollment_id' => Enrollment::factory(),
            'student_profile_id' => StudentProfile::factory(),
            'payment_id' => null,
            'ledger_entry_id' => null,
            'status' => AdmissionCapacityReservation::StatusSecured,
            'secured_at' => now(),
            'scope_snapshot' => [],
            'meta' => [],
        ];
    }
}
