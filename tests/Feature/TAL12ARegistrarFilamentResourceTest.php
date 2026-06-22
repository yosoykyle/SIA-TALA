<?php

namespace Tests\Feature;

use App\Models\ScheduleChange;
use App\Models\User;
use App\Policies\ScheduleChangePolicy;
use Tests\TestCase;

class TAL12ARegistrarFilamentResourceTest extends TestCase
{
    public function test_registrar_resources_use_registrar_navigation_group_and_permissions(): void
    {
        $resources = [
            'Enrollments/EnrollmentResource.php' => ['Registrar', 'approve-documents'],
            'DocumentUploads/DocumentUploadResource.php' => ['Registrar', 'approve-documents'],
            'ImportBatches/ImportBatchResource.php' => ['Registrar', 'manage-curricula'],
            'Sections/SectionResource.php' => ['Registrar', 'manage-schedules'],
            'SectionMeetings/SectionMeetingResource.php' => ['Registrar', 'manage-schedules'],
            'ScheduleGenerationRuns/ScheduleGenerationRunResource.php' => ['Registrar', 'manage-schedules'],
            'FacultyAvailabilityPeriods/FacultyAvailabilityPeriodResource.php' => ['Registrar', 'review-lock-faculty-availability'],
            'FacultyAvailabilitySubmissions/FacultyAvailabilitySubmissionResource.php' => ['Registrar', 'review-lock-faculty-availability'],
            'ScheduleChanges/ScheduleChangeResource.php' => ['Registrar', 'manage-schedules'],
            'CorVerifications/CorVerificationResource.php' => ['Registrar', 'manage-cor-verifications'],
        ];

        foreach ($resources as $relativePath => [$navigationGroup, $permission]) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringContainsString("'{$navigationGroup}'", $source);
            $this->assertStringContainsString($permission, $this->relatedPolicySource($permission));
        }
    }

    public function test_document_request_resource_is_removed_from_admin_scope(): void
    {
        $this->assertDirectoryDoesNotExist(app_path('Filament/Resources/DocumentRequests'));
        $this->assertFileDoesNotExist(app_path('Models/DocumentRequest.php'));
        $this->assertFileDoesNotExist(app_path('Policies/DocumentRequestPolicy.php'));
    }

    public function test_enrollment_table_keeps_registrar_and_accounting_actions_permission_scoped(): void
    {
        $source = $this->resourceSource('Enrollments/Tables/EnrollmentsTable.php');

        $this->assertStringContainsString('markHardCopyReceived', $source);
        $this->assertStringContainsString('assess', $source);
        $this->assertStringContainsString('confirmPayment', $source);
        $this->assertStringContainsString('EnrollmentHardCopyReceiptService', $source);
        $this->assertStringContainsString("can('markHardCopyReceived'", $source);
        $this->assertStringContainsString("can('assess'", $source);
        $this->assertStringContainsString("can('confirmPayment'", $source);
        $this->assertStringNotContainsString('DB::transaction', $source);
        $this->assertStringNotContainsString('json_encode', $source);
        $this->assertStringNotContainsString('studentProfile()->update', $source);
    }

    public function test_enrollments_are_lifecycle_action_surfaces_not_generic_crud(): void
    {
        foreach ([
            'Enrollments/Pages/CreateEnrollment.php',
            'Enrollments/Pages/EditEnrollment.php',
            'Enrollments/Schemas/EnrollmentForm.php',
        ] as $relativePath) {
            $this->assertFileDoesNotExist(app_path("Filament/Resources/{$relativePath}"));
        }

        $resource = $this->resourceSource('Enrollments/EnrollmentResource.php');
        $listPage = $this->resourceSource('Enrollments/Pages/ListEnrollments.php');
        $viewPage = $this->resourceSource('Enrollments/Pages/ViewEnrollment.php');
        $table = $this->resourceSource('Enrollments/Tables/EnrollmentsTable.php');
        $policy = file_get_contents(app_path('Policies/EnrollmentPolicy.php'));

        $this->assertIsString($policy);
        $this->assertStringNotContainsString("CreateEnrollment::route('/create')", $resource);
        $this->assertStringNotContainsString("EditEnrollment::route('/{record}/edit')", $resource);
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringContainsString('markHardCopyReceivedAction', $table);
        $this->assertStringContainsString('assessAction', $table);
        $this->assertStringContainsString('confirmPaymentAction', $table);
        $this->assertStringContainsString('public function create', $policy);
        $this->assertStringContainsString('public function update', $policy);
        $this->assertStringContainsString('return false;', $policy);
    }

    public function test_import_batches_are_read_only_audit_surface_until_dedicated_import_pages_exist(): void
    {
        $resource = $this->resourceSource('ImportBatches/ImportBatchResource.php');
        $listPage = $this->resourceSource('ImportBatches/Pages/ListImportBatches.php');
        $viewPage = $this->resourceSource('ImportBatches/Pages/ViewImportBatch.php');
        $table = $this->resourceSource('ImportBatches/Tables/ImportBatchesTable.php');

        $this->assertStringContainsString('Import Batch Audit', $resource);
        $this->assertStringContainsString('public static function canCreate(): bool', $resource);
        $this->assertStringNotContainsString("CreateImportBatch::route('/create')", $resource);
        $this->assertStringNotContainsString("EditImportBatch::route('/{record}/edit')", $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ImportBatches/Pages/CreateImportBatch.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ImportBatches/Pages/EditImportBatch.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ImportBatches/Schemas/ImportBatchForm.php'));
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringContainsString('commit', $table);
        $this->assertStringContainsString('cancel', $table);
        $this->assertStringContainsString('ImportBatchLifecycleService', $table);
        $this->assertStringContainsString('ImportBatch::importTypeOptions()', $table);
        $this->assertStringContainsString('ImportBatch::statusOptions()', $table);
        $this->assertStringNotContainsString('DB::transaction', $table);
        $this->assertStringNotContainsString("'status' => 'committed'", $table);
    }

    public function test_document_uploads_are_review_only_not_generic_create_edit_crud(): void
    {
        $resource = $this->resourceSource('DocumentUploads/DocumentUploadResource.php');
        $listPage = $this->resourceSource('DocumentUploads/Pages/ListDocumentUploads.php');
        $viewPage = $this->resourceSource('DocumentUploads/Pages/ViewDocumentUpload.php');
        $infolist = $this->resourceSource('DocumentUploads/Schemas/DocumentUploadInfolist.php');
        $table = $this->resourceSource('DocumentUploads/Tables/DocumentUploadsTable.php');

        $this->assertStringNotContainsString("CreateDocumentUpload::route('/create')", $resource);
        $this->assertStringNotContainsString("EditDocumentUpload::route('/{record}/edit')", $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/DocumentUploads/Pages/CreateDocumentUpload.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/DocumentUploads/Pages/EditDocumentUpload.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/DocumentUploads/Schemas/DocumentUploadForm.php'));
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringContainsString('approveAction', $table);
        $this->assertStringContainsString('needsCorrectionAction', $table);
        $this->assertStringContainsString('rejectAction', $table);
        $this->assertStringContainsString('DocumentUploadReviewService', $table);
        $this->assertStringContainsString('DocumentUpload::reviewStatusOptions()', $table);
        $this->assertStringContainsString('isRegistrarReviewable()', $table);
        $this->assertStringContainsString("TextEntry::make('studentProfile.student_id')", $infolist);
        $this->assertStringContainsString("TextEntry::make('studentProfile.user.name')", $infolist);
        $this->assertStringContainsString("TextEntry::make('user.name')", $infolist);
        $this->assertStringContainsString("TextEntry::make('term.term_name')", $infolist);
        $this->assertStringContainsString("TextEntry::make('registrarReviewer.name')", $infolist);
        $this->assertStringContainsString('DocumentUpload::reviewStatusColor($record->review_status)', $infolist);
        $this->assertStringNotContainsString("TextEntry::make('student_profile_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('user_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('term_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('registrar_reviewed_by')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('file_path')", $infolist);
        $this->assertStringNotContainsString('DB::transaction', $table);
        $this->assertStringNotContainsString('json_encode', $table);
        $this->assertStringNotContainsString("'review_status' =>", $table);
    }

    public function test_cor_controls_are_token_evidence_with_lifecycle_actions_not_generic_crud(): void
    {
        $resource = $this->resourceSource('CorVerifications/CorVerificationResource.php');
        $listPage = $this->resourceSource('CorVerifications/Pages/ListCorVerifications.php');
        $viewPage = $this->resourceSource('CorVerifications/Pages/ViewCorVerification.php');
        $table = $this->resourceSource('CorVerifications/Tables/CorVerificationsTable.php');
        $infolist = $this->resourceSource('CorVerifications/Schemas/CorVerificationInfolist.php');

        $this->assertStringNotContainsString("CreateCorVerification::route('/create')", $resource);
        $this->assertStringNotContainsString("EditCorVerification::route('/{record}/edit')", $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/CorVerifications/Pages/CreateCorVerification.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/CorVerifications/Pages/EditCorVerification.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/CorVerifications/Schemas/CorVerificationForm.php'));
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringContainsString('supersedeAction', $table);
        $this->assertStringContainsString('revokeAction', $table);
        $this->assertStringContainsString('CorVerificationLifecycleService', $table);
        $this->assertStringContainsString('CorVerification::statusOptions()', $table);
        $this->assertStringContainsString('isValid()', $table);
        $this->assertStringContainsString('isRevoked()', $table);
        $this->assertStringNotContainsString('DB::transaction', $table);
        $this->assertStringNotContainsString("'status' =>", $table);
        $this->assertStringContainsString("TextEntry::make('studentProfile.student_id')", $infolist);
        $this->assertStringContainsString("TextEntry::make('studentProfile.user.name')", $infolist);
        $this->assertStringContainsString("TextEntry::make('term.term_name')", $infolist);
        $this->assertStringContainsString('->enrollment?->displayLabel()', $infolist);
        $this->assertStringNotContainsString("TextEntry::make('student_profile_id')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('term_id')", $infolist);
    }

    public function test_schedule_generation_runs_are_service_created_drafts_not_generic_crud(): void
    {
        $resource = $this->resourceSource('ScheduleGenerationRuns/ScheduleGenerationRunResource.php');
        $listPage = $this->resourceSource('ScheduleGenerationRuns/Pages/ListScheduleGenerationRuns.php');
        $viewPage = $this->resourceSource('ScheduleGenerationRuns/Pages/ViewScheduleGenerationRun.php');
        $table = $this->resourceSource('ScheduleGenerationRuns/Tables/ScheduleGenerationRunsTable.php');

        $this->assertStringNotContainsString("CreateScheduleGenerationRun::route('/create')", $resource);
        $this->assertStringNotContainsString("EditScheduleGenerationRun::route('/{record}/edit')", $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ScheduleGenerationRuns/Pages/CreateScheduleGenerationRun.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ScheduleGenerationRuns/Pages/EditScheduleGenerationRun.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ScheduleGenerationRuns/Schemas/ScheduleGenerationRunForm.php'));
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringContainsString('DraftRowsRelationManager::class', $resource);
        $this->assertStringContainsString('commitAction', $table);
        $this->assertStringContainsString('ScheduleCommitService', $table);
        $this->assertStringContainsString('ScheduleGenerationRun::statusOptions()', $table);
        $this->assertStringContainsString('canBeCommitted()', $table);
        $this->assertStringNotContainsString('DB::transaction', $table);
        $this->assertStringNotContainsString("'status' => 'committed'", $table);
    }

    public function test_section_meetings_use_typed_manual_assignment_without_raw_commit_fields_or_direct_edit(): void
    {
        $resource = $this->resourceSource('SectionMeetings/SectionMeetingResource.php');
        $form = $this->resourceSource('SectionMeetings/Schemas/SectionMeetingForm.php');
        $createPage = $this->resourceSource('SectionMeetings/Pages/CreateSectionMeeting.php');
        $listPage = $this->resourceSource('SectionMeetings/Pages/ListSectionMeetings.php');
        $viewPage = $this->resourceSource('SectionMeetings/Pages/ViewSectionMeeting.php');
        $table = $this->resourceSource('SectionMeetings/Tables/SectionMeetingsTable.php');
        $policy = file_get_contents(app_path('Policies/SectionMeetingPolicy.php'));

        $this->assertIsString($policy);
        $this->assertStringContainsString("CreateSectionMeeting::route('/create')", $resource);
        $this->assertStringNotContainsString("EditSectionMeeting::route('/{record}/edit')", $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/SectionMeetings/Pages/EditSectionMeeting.php'));

        foreach ([
            "Select::make('term_id')",
            "Select::make('section_id')",
            "Select::make('subject_id')",
            "Select::make('faculty_id')",
            "Select::make('day_of_week')",
            "TimePicker::make('starts_at')",
            "TimePicker::make('ends_at')",
            "Select::make('modality')",
            "TextInput::make('room')",
            "Textarea::make('availability_override_reason')",
        ] as $typedField) {
            $this->assertStringContainsString($typedField, $form);
        }

        foreach ([
            "TextInput::make('term_id')",
            "TextInput::make('section_id')",
            "TextInput::make('subject_id')",
            "TextInput::make('faculty_id')",
            "TextInput::make('day_of_week')",
            "TextInput::make('modality')",
            "TextInput::make('schedule_generation_run_id')",
            "TextInput::make('committed_by')",
            "DateTimePicker::make('committed_at')",
        ] as $rawField) {
            $this->assertStringNotContainsString($rawField, $form);
        }

        $this->assertStringContainsString('SectionMeetingAssignmentService', $createPage);
        $this->assertStringContainsString('prepareForCreate', $createPage);
        $this->assertStringContainsString("TextEntry::make('availability_override_reason')", $viewPage.$this->resourceSource('SectionMeetings/Schemas/SectionMeetingInfolist.php'));
        $this->assertStringContainsString('Manual Assignment', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringNotContainsString('DeleteAction::make()', $table);
        $this->assertStringContainsString('public function create', $policy);
        $this->assertStringContainsString("can('manage-schedules')", $policy);
        $this->assertStringContainsString('public function update', $policy);
        $this->assertStringContainsString('return false;', $policy);
    }

    public function test_generic_service_request_resource_is_removed_from_registrar_scope(): void
    {
        $this->assertFileDoesNotExist(app_path('Models/ServiceRequest.php'));
        $this->assertFileDoesNotExist(app_path('Policies/ServiceRequestPolicy.php'));
        $this->assertFileDoesNotExist(app_path('Actions/ServiceRequests/ServiceRequestLifecycleService.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ServiceRequests/ServiceRequestResource.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ServiceRequests/Pages/CreateServiceRequest.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ServiceRequests/Pages/EditServiceRequest.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ServiceRequests/Schemas/ServiceRequestForm.php'));
        $this->assertDirectoryDoesNotExist(app_path('Filament/Resources/ServiceRequests'));
    }

    public function test_schedule_change_form_uses_typed_fields_not_raw_json_textareas(): void
    {
        $form = $this->resourceSource('ScheduleChanges/Schemas/ScheduleChangeForm.php');
        $createPage = $this->resourceSource('ScheduleChanges/Pages/CreateScheduleChange.php');
        $editPage = $this->resourceSource('ScheduleChanges/Pages/EditScheduleChange.php');
        $table = $this->resourceSource('ScheduleChanges/Tables/ScheduleChangesTable.php');
        $policy = file_get_contents(app_path('Policies/ScheduleChangePolicy.php'));

        $this->assertIsString($policy);

        foreach (['new_faculty_id', 'new_room', 'new_day_of_week', 'new_starts_at', 'new_ends_at', 'new_modality'] as $field) {
            $this->assertStringContainsString($field, $form);
        }

        $this->assertStringNotContainsString("Textarea::make('old_payload')", $form);
        $this->assertStringNotContainsString("Textarea::make('new_payload')", $form);
        $this->assertStringNotContainsString("->relationship('sectionMeeting', 'id')", $form);
        $this->assertStringContainsString('SectionMeeting::scheduleChangeOptionsFor', $form);
        $this->assertStringContainsString('->live()', $form);
        $this->assertStringContainsString('->afterStateUpdated', $form);
        $this->assertStringContainsString("->disabled(fn (Get \$get): bool => blank(\$get('term_id')))", $form);
        $this->assertStringContainsString('ScheduleChange::validateTargetMeetingData', $createPage);
        $this->assertStringContainsString('ScheduleChange::validateTargetMeetingData', $editPage);
        $this->assertStringContainsString('ScheduleChangePayload::fromSectionMeeting', $createPage);
        $this->assertStringContainsString('ScheduleChangePayload::fromFormData', $createPage);
        $this->assertStringContainsString('ScheduleChangePayload::fromFormData', $editPage);
        $this->assertStringContainsString('ScheduleChangeLifecycleService', $table);
        $this->assertStringContainsString('ScheduleChange::statusOptions()', $table);
        $this->assertStringContainsString('isProposed()', $table);
        $this->assertStringContainsString('isApproved()', $table);
        $this->assertStringNotContainsString('SectionMeetingAssignmentService', $table);
        $this->assertStringNotContainsString('DB::transaction', $table);
        $this->assertStringNotContainsString('json_encode', $table);
        $this->assertStringContainsString("\$scheduleChange->status === 'proposed'", $policy);
        $this->assertStringContainsString('$record->isProposed()', $table);
    }

    public function test_schedule_change_direct_edit_policy_is_limited_to_proposed_requests(): void
    {
        $policy = app(ScheduleChangePolicy::class);
        $registrar = new class extends User
        {
            public function can($abilities, $arguments = []): bool
            {
                return $abilities === 'manage-schedules';
            }
        };

        $this->assertTrue($policy->update($registrar, new ScheduleChange(['status' => 'proposed'])));

        foreach (['approved', 'applied', 'rejected'] as $status) {
            $this->assertFalse($policy->update($registrar, new ScheduleChange(['status' => $status])));
        }
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }

    private function relatedPolicySource(string $permission): string
    {
        foreach (glob(app_path('Policies/*.php')) ?: [] as $policyFile) {
            $source = file_get_contents($policyFile);

            if (is_string($source) && str_contains($source, $permission)) {
                return $source;
            }
        }

        $this->fail("No policy source contains {$permission}.");
    }
}
