<?php

namespace Database\Factories;

use App\Models\CurriculumEntry;
use App\Models\Term;
use App\Models\TermOffering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TermOffering>
 */
class TermOfferingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'term_id' => Term::factory(),
            'curriculum_entry_id' => CurriculumEntry::factory(),
            'category' => TermOffering::CategoryRegular,
            'special_reason' => null,
            'delivery_variant' => TermOffering::ArrangementNormalClass,
            'modality' => TermOffering::ModalityFaceToFace,
            'expected_count' => 30,
            'room_type_override' => null,
            'same_faculty_override' => null,
            'state' => TermOffering::StatePendingScheduling,
        ];
    }

    public function special(?string $reason = null): static
    {
        return $this->state(fn (): array => [
            'category' => TermOffering::CategorySpecial,
            'special_reason' => $reason ?? 'Petitioned Demand',
            'delivery_variant' => TermOffering::ArrangementTutorial,
        ]);
    }
}
