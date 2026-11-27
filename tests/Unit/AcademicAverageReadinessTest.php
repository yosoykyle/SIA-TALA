<?php

namespace Tests\Unit;

use App\Actions\Academics\AcademicAverageReadiness;
use App\Models\CourseSpecification;
use PHPUnit\Framework\TestCase;

final class AcademicAverageReadinessTest extends TestCase
{
    public function test_tesda_competency_is_included_in_gwa_and_non_credit_is_excluded(): void
    {
        $this->assertFalse(CourseSpecification::isExcludedFromGwa(CourseSpecification::AcademicClassificationTesdaCompetency));
        $this->assertTrue(CourseSpecification::isExcludedFromGwa(CourseSpecification::AcademicClassificationNonCredit));

        $averageReadiness = new AcademicAverageReadiness;

        $results = collect([
            [
                'outcome_complete' => true,
                'event' => (object) ['id' => 1],
                'course_specification' => new CourseSpecification([
                    'academic_classification' => CourseSpecification::AcademicClassificationTesdaCompetency,
                ]),
                'result' => '1.50',
                'units' => '3.00',
                'is_external_transfer' => false,
                'inc_settled' => false,
            ],
            [
                'outcome_complete' => true,
                'event' => (object) ['id' => 2],
                'course_specification' => new CourseSpecification([
                    'academic_classification' => CourseSpecification::AcademicClassificationNonCredit,
                ]),
                'result' => '3.00',
                'units' => '3.00',
                'is_external_transfer' => false,
                'inc_settled' => false,
            ],
        ]);

        $this->assertSame(AcademicAverageReadiness::Available, $averageReadiness->forResults($results));
        $this->assertCount(1, $averageReadiness->includedResults($results));
        $this->assertSame('1.50', $averageReadiness->average($results));
    }
}
