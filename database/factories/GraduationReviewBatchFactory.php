<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\GraduationReviewBatch;
use App\Models\Term;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GraduationReviewBatch> */
class GraduationReviewBatchFactory extends Factory
{
    public function definition(): array
    {
        $academicYear = AcademicYear::factory();

        return [
            'academic_year_id' => $academicYear,
            'term_id' => Term::factory()->state(['academic_year_id' => $academicYear]),
            'name' => 'Completion Review '.fake()->unique()->numberBetween(1000, 9999),
            'state' => GraduationReviewBatch::StateOpen,
            'created_by' => null,
            'filter_summary' => null,
            'closed_at' => null,
        ];
    }
}
