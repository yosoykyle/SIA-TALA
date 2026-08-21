<?php

namespace Database\Factories;

use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationAdjustmentFinanceConfirmation>
 */
class RegistrationAdjustmentFinanceConfirmationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'current_course_enrollment_id' => fn (array $attributes): Factory => CourseEnrollment::factory()
                ->state(['enrollment_id' => $attributes['enrollment_id']]),
            'replacement_section_id' => function (array $attributes): Factory {
                $enrollment = Enrollment::query()->findOrFail($attributes['enrollment_id']);

                return Section::factory()->state([
                    'term_offering_id' => TermOffering::factory()->state(['term_id' => $enrollment->term_id]),
                ]);
            },
            'financial_effect' => RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost,
            'authority_reference' => 'SYN-ACCOUNTING-NO-COST-'.fake()->unique()->numerify('#####'),
            'confirmed_by' => User::factory(),
            'confirmed_at' => now(),
        ];
    }
}
