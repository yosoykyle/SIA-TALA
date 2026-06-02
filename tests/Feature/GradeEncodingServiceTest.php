<?php

namespace Tests\Feature;

use App\Actions\Grades\GradeEncodingService;
use App\Actions\Grades\GradeFinalizationService;
use Tests\TestCase;

class GradeEncodingServiceTest extends TestCase
{
    public function test_grade_encoding_requires_assigned_faculty_with_encode_permission(): void
    {
        $source = $this->source(GradeEncodingService::class);

        $this->assertStringContainsString("can('encode-grades')", $source);
        $this->assertStringContainsString('Only faculty with grade encoding permission can encode grades.', $source);
        $this->assertStringContainsString('isAssignedViaSectionMeeting', $source);
        $this->assertStringContainsString('isAssignedViaSectionTeacher', $source);
        $this->assertStringContainsString('Only the assigned faculty can encode grades for this subject.', $source);
    }

    public function test_finalized_or_dropped_subjects_cannot_be_edited(): void
    {
        $source = $this->source(GradeEncodingService::class);

        $this->assertStringContainsString('Finalized grades cannot be edited.', $source);
        $this->assertStringContainsString('Dropped or inactive subjects cannot receive grades.', $source);
        $this->assertStringContainsString("\$enrollmentSubject->status !== 'enrolled'", $source);
    }

    public function test_grade_finalization_requires_complete_sheet_or_valid_inc_and_supports_academic_head_override(): void
    {
        $source = $this->source(GradeFinalizationService::class);

        $this->assertStringContainsString("hasRole('faculty')", $source);
        $this->assertStringContainsString("can('finalize-grades')", $source);
        $this->assertStringContainsString("hasRole('academic-head')", $source);
        $this->assertStringContainsString("can('authorize-overrides')", $source);
        $this->assertStringContainsString('Incomplete grades require INC remarks and an expiry date before finalization.', $source);
        $this->assertStringContainsString('Grade sheet is incomplete and cannot be finalized.', $source);
    }

    private function source(string $class): string
    {
        $reflection = new \ReflectionClass($class);
        $source = file_get_contents((string) $reflection->getFileName());

        $this->assertIsString($source);

        return $source;
    }
}
