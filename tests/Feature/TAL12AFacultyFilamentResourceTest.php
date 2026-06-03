<?php

namespace Tests\Feature;

use Tests\TestCase;

class TAL12AFacultyFilamentResourceTest extends TestCase
{
    public function test_faculty_navigation_contains_class_list_and_correction_resources_only_for_faculty_context(): void
    {
        $enrollmentSubjects = $this->resourceSource('EnrollmentSubjects/EnrollmentSubjectResource.php');
        $gradeCorrections = $this->resourceSource('GradeCorrections/GradeCorrectionResource.php');
        $sectionMeetings = $this->resourceSource('SectionMeetings/SectionMeetingResource.php');

        $this->assertStringContainsString("'Faculty'", $enrollmentSubjects);
        $this->assertStringContainsString("'Faculty'", $gradeCorrections);
        $this->assertStringContainsString("hasRole('faculty')", $enrollmentSubjects);
        $this->assertStringContainsString("hasRole('faculty')", $gradeCorrections);
        $this->assertStringContainsString("hasRole('faculty')", $sectionMeetings);
    }

    public function test_faculty_grade_actions_are_policy_scoped_to_encoding_incomplete_and_finalization(): void
    {
        $table = $this->resourceSource('EnrollmentSubjects/Tables/EnrollmentSubjectsTable.php');
        $policy = file_get_contents(app_path('Policies/EnrollmentSubjectPolicy.php'));

        $this->assertIsString($policy);

        foreach (['encodeGrade', 'markIncomplete', 'finalizeGrade'] as $action) {
            $this->assertStringContainsString($action, $table);
            $this->assertStringContainsString($action, $policy);
        }

        $this->assertStringContainsString('encode-grades', $policy);
        $this->assertStringContainsString('finalize-grades', $policy);
        $this->assertStringContainsString('isAssignedToFaculty', $policy);
    }

    public function test_grade_correction_actions_follow_registrar_review_and_resolution_flow(): void
    {
        $table = $this->resourceSource('GradeCorrections/Tables/GradeCorrectionsTable.php');
        $policy = file_get_contents(app_path('Policies/GradeCorrectionPolicy.php'));

        $this->assertIsString($policy);

        foreach (['startReview', 'reject', 'resolveWithoutGradeChange', 'resolveWithGradeChange'] as $action) {
            $this->assertStringContainsString($action, $table);
            $this->assertStringContainsString($action, $policy);
        }

        $this->assertStringContainsString('Record Approved Grade Change', $table);
        $this->assertStringContainsString('Academic Head who approved offline', $table);
        $this->assertStringContainsString('Use only after the Academic Head has already approved', $table);
        $this->assertStringContainsString('manage-grade-corrections', $policy);
    }

    public function test_grade_correction_resource_is_lifecycle_surface_not_generic_crud(): void
    {
        foreach ([
            'GradeCorrections/Pages/CreateGradeCorrection.php',
            'GradeCorrections/Pages/EditGradeCorrection.php',
            'GradeCorrections/Schemas/GradeCorrectionForm.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->resourceSource('GradeCorrections/GradeCorrectionResource.php');

        $this->assertStringNotContainsString("'create'", $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString('function form(', $resource);

        $table = $this->resourceSource('GradeCorrections/Tables/GradeCorrectionsTable.php');
        $listPage = $this->resourceSource('GradeCorrections/Pages/ListGradeCorrections.php');
        $viewPage = $this->resourceSource('GradeCorrections/Pages/ViewGradeCorrection.php');

        foreach (['CreateAction::make', 'EditAction::make', 'DeleteAction::make'] as $genericAction) {
            $this->assertStringNotContainsString($genericAction, $table);
            $this->assertStringNotContainsString($genericAction, $listPage);
            $this->assertStringNotContainsString($genericAction, $viewPage);
        }

        foreach ([
            "TextInput::make('user_id')",
            "TextInput::make('current_grade')",
            "TextInput::make('attachment_paths')",
            "Select::make('status')",
            "TextInput::make('assigned_to')",
            "Select::make('creator_id')",
        ] as $rawField) {
            $this->assertStringNotContainsString($rawField, $resource);
            $this->assertStringNotContainsString($rawField, $table);
        }

        foreach (['startReview', 'reject', 'resolveWithoutGradeChange', 'resolveWithGradeChange'] as $lifecycleAction) {
            $this->assertStringContainsString($lifecycleAction, $table);
        }
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
