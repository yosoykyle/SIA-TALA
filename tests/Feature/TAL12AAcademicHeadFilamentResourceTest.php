<?php

namespace Tests\Feature;

use Tests\TestCase;

class TAL12AAcademicHeadFilamentResourceTest extends TestCase
{
    public function test_academic_head_navigation_is_oversight_or_override_scoped(): void
    {
        foreach ([
            'Grades/GradeResource.php',
            'GradeCorrections/GradeCorrectionResource.php',
            'EnrollmentSubjects/EnrollmentSubjectResource.php',
            'SectionMeetings/SectionMeetingResource.php',
            'Enrollments/EnrollmentResource.php',
            'DocumentRequests/DocumentRequestResource.php',
        ] as $relativePath) {
            $source = $this->resourceSource($relativePath);

            $this->assertTrue(
                str_contains($source, 'academic-head') || str_contains($source, 'Academic Head'),
                "{$relativePath} should define Academic Head visibility or navigation.",
            );
        }
    }

    public function test_academic_head_grade_override_actions_require_authorize_overrides_permission(): void
    {
        $gradeTable = $this->resourceSource('Grades/Tables/GradesTable.php');
        $gradePolicy = file_get_contents(app_path('Policies/GradePolicy.php'));
        $finalizationService = file_get_contents(app_path('Actions/Grades/GradeFinalizationService.php'));

        $this->assertIsString($gradePolicy);
        $this->assertIsString($finalizationService);

        foreach (['forceFinalize', 'reopen'] as $action) {
            $this->assertStringContainsString($action, $gradeTable);
            $this->assertStringContainsString($action, $gradePolicy);
        }

        $this->assertStringContainsString("hasRole('academic-head')", $gradePolicy);
        $this->assertStringContainsString("can('authorize-overrides')", $gradePolicy);
        $this->assertStringContainsString('Only the Academic Head can authorize grade finalization overrides.', $finalizationService);
    }

    public function test_grade_oversight_is_not_generic_grade_crud(): void
    {
        foreach ([
            'Grades/Pages/CreateGrade.php',
            'Grades/Pages/EditGrade.php',
            'Grades/Schemas/GradeForm.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->resourceSource('Grades/GradeResource.php');

        $this->assertStringNotContainsString("'create'", $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString('function form(', $resource);

        $table = $this->resourceSource('Grades/Tables/GradesTable.php');

        $this->assertStringContainsString('forceFinalize', $table);
        $this->assertStringContainsString('reopen', $table);
        $this->assertStringContainsString('Override Reason', $table);
        $this->assertStringNotContainsString("TextInput::make('finalized_by')", $table);
        $this->assertStringNotContainsString("TextInput::make('reopened_by')", $table);
        $this->assertStringNotContainsString("Toggle::make('is_finalized')", $table);
    }

    public function test_academic_head_finance_visibility_is_read_only_summary_not_accounting_mutation(): void
    {
        foreach ([
            'FeeTemplatePolicy.php',
            'InstallmentPolicyPolicy.php',
            'PromissoryNotePolicy.php',
        ] as $policyFile) {
            $source = file_get_contents(app_path("Policies/{$policyFile}"));

            $this->assertIsString($source);
            $this->assertStringContainsString('view-global-records', $source, "{$policyFile} should allow Academic Head summary oversight through global read permission.");
        }

        foreach (['PaymentPolicy.php', 'LedgerEntryPolicy.php', 'PromissoryNotePolicy.php'] as $policyFile) {
            $source = file_get_contents(app_path("Policies/{$policyFile}"));

            $this->assertIsString($source);
            $this->assertStringNotContainsString("hasRole('academic-head') && \$user->can('process-payments')", $source);
        }
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
