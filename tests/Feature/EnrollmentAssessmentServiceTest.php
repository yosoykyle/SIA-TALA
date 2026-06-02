<?php

namespace Tests\Feature;

use App\Actions\Enrollment\EnrollmentAssessmentService;
use App\Models\Enrollment;
use Tests\TestCase;

class EnrollmentAssessmentServiceTest extends TestCase
{
    public function test_freshmen_discount_eligibility_is_limited_to_new_grade_11_or_first_year_students(): void
    {
        $this->assertTrue($this->enrollment('new', 'grade 11')->isFreshmenDiscountEligible());
        $this->assertTrue($this->enrollment('freshman', '1st year')->isFreshmenDiscountEligible());
        $this->assertTrue($this->enrollment('freshmen', '1')->isFreshmenDiscountEligible());

        $this->assertFalse($this->enrollment('transferee', '1st year')->isFreshmenDiscountEligible());
        $this->assertFalse($this->enrollment('new', '2nd year')->isFreshmenDiscountEligible());
        $this->assertFalse($this->enrollment('old', 'grade 11')->isFreshmenDiscountEligible());
    }

    public function test_assessment_service_applies_automated_discount_to_tuition_fee_only(): void
    {
        $source = $this->source(EnrollmentAssessmentService::class);

        $this->assertStringContainsString('$feeTemplate->tuition_fee', $source);
        $this->assertStringContainsString("multiplyPercent((string) \$feeTemplate->tuition_fee, '50.00')", $source);
        $this->assertStringContainsString('Automated Freshmen Discount - 50% Tuition Fee', $source);
        $this->assertStringNotContainsString("multiplyPercent(\$grossAssessment, '50.00')", $source);
        $this->assertStringNotContainsString("multiplyPercent((string) \$feeTemplate->misc_fee, '50.00')", $source);
        $this->assertStringNotContainsString("multiplyPercent((string) \$feeTemplate->laboratory_fee, '50.00')", $source);
        $this->assertStringNotContainsString("multiplyPercent((string) \$feeTemplate->other_fee, '50.00')", $source);
    }

    public function test_assessment_service_is_idempotent_for_existing_assessment_entries(): void
    {
        $source = $this->source(EnrollmentAssessmentService::class);

        $this->assertStringContainsString('hasAssessmentEntries', $source);
        $this->assertStringContainsString('summaryForExistingAssessment', $source);
        $this->assertStringContainsString('summaryForExistingAssessment($enrollment, $studentProfile, true)', $source);
        $this->assertStringContainsString("'already_assessed' => \$alreadyAssessed", $source);
    }

    private function enrollment(string $studentType, string $yearLevel): Enrollment
    {
        return new Enrollment([
            'student_type' => $studentType,
            'year_level' => $yearLevel,
        ]);
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }
}
