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
        $this->assertStringNotContainsString('CreateAction::make()', $listPage);
        $this->assertStringNotContainsString('EditAction::make()', $viewPage);
        $this->assertStringNotContainsString('EditAction::make()', $table);
        $this->assertStringContainsString('commit', $table);
        $this->assertStringContainsString('cancel', $table);
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
