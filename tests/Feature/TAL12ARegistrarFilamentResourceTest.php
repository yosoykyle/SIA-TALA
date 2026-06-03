<?php

namespace Tests\Feature;

use Tests\TestCase;

class TAL12ARegistrarFilamentResourceTest extends TestCase
{
    public function test_registrar_resources_use_registrar_navigation_group_and_permissions(): void
    {
        $resources = [
            'Enrollments/EnrollmentResource.php' => ['Registrar', 'approve-documents'],
            'DocumentRequests/DocumentRequestResource.php' => ['Registrar', 'manage-document-requests'],
            'DocumentUploads/DocumentUploadResource.php' => ['Registrar', 'approve-documents'],
            'ImportBatches/ImportBatchResource.php' => ['Registrar', 'manage-curricula'],
            'SectionMeetings/SectionMeetingResource.php' => ['Registrar', 'manage-schedules'],
            'ScheduleGenerationRuns/ScheduleGenerationRunResource.php' => ['Registrar', 'manage-schedules'],
            'ScheduleChanges/ScheduleChangeResource.php' => ['Registrar', 'manage-schedules'],
            'CorVerifications/CorVerificationResource.php' => ['Registrar', 'manage-lis'],
        ];

        foreach ($resources as $relativePath => [$navigationGroup, $permission]) {
            $source = $this->resourceSource($relativePath);

            $this->assertStringContainsString("'{$navigationGroup}'", $source);
            $this->assertStringContainsString($permission, $this->relatedPolicySource($permission));
        }
    }

    public function test_document_request_table_exposes_registrar_processing_and_accounting_payment_boundaries(): void
    {
        $source = $this->resourceSource('DocumentRequests/Tables/DocumentRequestsTable.php');

        $this->assertStringContainsString('manage-document-requests', $source);
        $this->assertStringContainsString('process-payments', $source);
        $this->assertStringContainsString('markReadyForPickup', $source);
        $this->assertStringContainsString('markShipped', $source);
        $this->assertStringContainsString('confirmDocumentFee', $source);
        $this->assertStringContainsString('confirmShippingPayment', $source);
    }

    public function test_enrollment_table_keeps_registrar_and_accounting_actions_permission_scoped(): void
    {
        $source = $this->resourceSource('Enrollments/Tables/EnrollmentsTable.php');

        $this->assertStringContainsString('markHardCopyReceived', $source);
        $this->assertStringContainsString('assess', $source);
        $this->assertStringContainsString('confirmPayment', $source);
        $this->assertStringContainsString("can('markHardCopyReceived'", $source);
        $this->assertStringContainsString("can('assess'", $source);
        $this->assertStringContainsString("can('confirmPayment'", $source);
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
    }

    public function test_document_uploads_are_review_only_not_generic_create_edit_crud(): void
    {
        $resource = $this->resourceSource('DocumentUploads/DocumentUploadResource.php');
        $listPage = $this->resourceSource('DocumentUploads/Pages/ListDocumentUploads.php');
        $viewPage = $this->resourceSource('DocumentUploads/Pages/ViewDocumentUpload.php');
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
    }

    public function test_cor_controls_are_token_evidence_with_lifecycle_actions_not_generic_crud(): void
    {
        $resource = $this->resourceSource('CorVerifications/CorVerificationResource.php');
        $listPage = $this->resourceSource('CorVerifications/Pages/ListCorVerifications.php');
        $viewPage = $this->resourceSource('CorVerifications/Pages/ViewCorVerification.php');
        $table = $this->resourceSource('CorVerifications/Tables/CorVerificationsTable.php');

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
        $this->assertStringContainsString('commitAction', $table);
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
        $this->assertStringContainsString('Manual Assignment', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringNotContainsString('DeleteAction::make()', $table);
        $this->assertStringContainsString('public function create', $policy);
        $this->assertStringContainsString("can('manage-schedules')", $policy);
        $this->assertStringContainsString('public function update', $policy);
        $this->assertStringContainsString('return false;', $policy);
    }

    public function test_service_requests_are_lifecycle_action_surfaces_not_generic_crud(): void
    {
        $resource = $this->resourceSource('ServiceRequests/ServiceRequestResource.php');
        $listPage = $this->resourceSource('ServiceRequests/Pages/ListServiceRequests.php');
        $viewPage = $this->resourceSource('ServiceRequests/Pages/ViewServiceRequest.php');
        $table = $this->resourceSource('ServiceRequests/Tables/ServiceRequestsTable.php');

        $this->assertStringNotContainsString("CreateServiceRequest::route('/create')", $resource);
        $this->assertStringNotContainsString("EditServiceRequest::route('/{record}/edit')", $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ServiceRequests/Pages/CreateServiceRequest.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ServiceRequests/Pages/EditServiceRequest.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ServiceRequests/Schemas/ServiceRequestForm.php'));
        $this->assertStringNotContainsString('function form(', $resource);
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringContainsString('startReviewAction', $table);
        $this->assertStringContainsString('resolveAction', $table);
        $this->assertStringContainsString('rejectAction', $table);
        $this->assertStringContainsString('cancelAction', $table);
    }

    public function test_schedule_change_form_uses_typed_fields_not_raw_json_textareas(): void
    {
        $form = $this->resourceSource('ScheduleChanges/Schemas/ScheduleChangeForm.php');
        $createPage = $this->resourceSource('ScheduleChanges/Pages/CreateScheduleChange.php');
        $editPage = $this->resourceSource('ScheduleChanges/Pages/EditScheduleChange.php');
        $table = $this->resourceSource('ScheduleChanges/Tables/ScheduleChangesTable.php');

        foreach (['new_faculty_id', 'new_room', 'new_day_of_week', 'new_starts_at', 'new_ends_at', 'new_modality'] as $field) {
            $this->assertStringContainsString($field, $form);
        }

        $this->assertStringNotContainsString("Textarea::make('old_payload')", $form);
        $this->assertStringNotContainsString("Textarea::make('new_payload')", $form);
        $this->assertStringContainsString('ScheduleChangePayload::fromSectionMeeting', $createPage);
        $this->assertStringContainsString('ScheduleChangePayload::fromFormData', $createPage);
        $this->assertStringContainsString('ScheduleChangePayload::fromFormData', $editPage);
        $this->assertStringContainsString('SectionMeetingAssignmentService', $table);
        $this->assertStringContainsString('prepareForScheduleChange', $table);
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
