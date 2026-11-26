<?php

namespace Database\Factories;

use App\Models\CurriculumEntry;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationCreditLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RegistrationCreditLine>
 */
class RegistrationCreditLineFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_credit_evaluation_id' => RegistrationCreditEvaluation::factory(),
            'curriculum_entry_id' => CurriculumEntry::factory(),
            'source_course_code' => 'PREV-'.fake()->unique()->numerify('###'),
            'source_course_title' => 'Previous school subject',
            'source_units' => '3.00',
            'tor_grade' => '2.00',
            'remarks' => null,
        ];
    }
}
