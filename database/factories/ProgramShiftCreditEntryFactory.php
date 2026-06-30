<?php

namespace Database\Factories;

use App\Models\CurriculumEntry;
use App\Models\ProgramShiftCreditEntry;
use App\Models\StudentLifecycleChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgramShiftCreditEntry>
 */
class ProgramShiftCreditEntryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_lifecycle_change_id' => StudentLifecycleChange::factory()->state([
                'type' => StudentLifecycleChange::TypeProgramShift,
            ]),
            'curriculum_entry_id' => CurriculumEntry::factory(),
            'treatment' => ProgramShiftCreditEntry::TreatmentDeficient,
            'state' => ProgramShiftCreditEntry::StateRecorded,
        ];
    }
}
