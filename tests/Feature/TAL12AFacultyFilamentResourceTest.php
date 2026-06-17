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

    public function test_faculty_class_list_is_grade_lifecycle_surface_not_enrollment_subject_crud(): void
    {
        foreach ([
            'EnrollmentSubjects/Pages/CreateEnrollmentSubject.php',
            'EnrollmentSubjects/Pages/EditEnrollmentSubject.php',
            'EnrollmentSubjects/Schemas/EnrollmentSubjectForm.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->resourceSource('EnrollmentSubjects/EnrollmentSubjectResource.php');

        $this->assertStringNotContainsString("'create'", $resource);
        $this->assertStringNotContainsString("'edit'", $resource);
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString('EnrollmentSubjectForm', $resource);

        $table = $this->resourceSource('EnrollmentSubjects/Tables/EnrollmentSubjectsTable.php');

        foreach (['CreateAction::make', 'EditAction::make', 'DeleteAction::make'] as $genericAction) {
            $this->assertStringNotContainsString($genericAction, $table);
        }

        foreach ([
            "TextInput::make('enrollment_id')",
            "TextInput::make('subject_id')",
            "TextInput::make('status')",
            "TextInput::make('section_meeting_id')",
            "Toggle::make('is_dropped')",
            "DateTimePicker::make('dropped_at')",
        ] as $rawField) {
            $this->assertStringNotContainsString($rawField, $resource);
            $this->assertStringNotContainsString($rawField, $table);
        }

        $this->assertStringContainsString('Encode College Grade', $table);
        $this->assertStringContainsString('Encode SHS Grade', $table);
        $this->assertStringContainsString('usesShsGrading()', $table);
        $this->assertStringContainsString("TextInput::make('prelim')", $table);
        $this->assertStringContainsString("TextInput::make('q1')", $table);
        $this->assertStringContainsString('GradeEncodingService', $table);
        $this->assertStringContainsString('GradeFinalizationService', $table);
    }

    public function test_grade_correction_actions_follow_registrar_review_and_resolution_flow(): void
    {
        $table = $this->resourceSource('GradeCorrections/Tables/GradeCorrectionsTable.php');
        $policy = file_get_contents(app_path('Policies/GradeCorrectionPolicy.php'));

        $this->assertIsString($policy);

        foreach (['startReview', 'reject', 'approveOfficialGradeChange', 'rejectOfficialGradeChange', 'resolveWithoutGradeChange', 'resolveWithGradeChange'] as $action) {
            $this->assertStringContainsString($action, $table);
            $this->assertStringContainsString($action, $policy);
        }

        $this->assertStringContainsString('Approve Official Grade Change', $table);
        $this->assertStringContainsString('Reject Official Grade Change', $table);
        $this->assertStringContainsString('Apply Approved Grade Change', $table);
        $this->assertStringContainsString("Textarea::make('approval_reason')", $table);
        $this->assertStringContainsString("Textarea::make('rejection_reason')", $table);
        $this->assertStringContainsString('hasAcademicHeadApproval', $policy);
        $this->assertStringContainsString("TextInput::make('college_prelim')", $table);
        $this->assertStringContainsString("TextInput::make('college_midterm')", $table);
        $this->assertStringContainsString("TextInput::make('college_final')", $table);
        $this->assertStringContainsString("TextInput::make('shs_q1')", $table);
        $this->assertStringContainsString("TextInput::make('shs_q2')", $table);
        $this->assertStringContainsString('usesCollegeGrading', $table);
        $this->assertStringContainsString('usesShsGrading', $table);
        $this->assertStringNotContainsString("TextInput::make('final_grade')", $table);
        $this->assertStringNotContainsString("TextInput::make('grade')", $table);
        $this->assertStringNotContainsString("TextInput::make('remarks')", $table);
        $this->assertStringNotContainsString('Academic Head who approved offline', $table);
        $this->assertStringNotContainsString('Use only after the Academic Head has already approved', $table);
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

        foreach (['startReview', 'reject', 'approveOfficialGradeChange', 'rejectOfficialGradeChange', 'resolveWithoutGradeChange', 'resolveWithGradeChange'] as $lifecycleAction) {
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
