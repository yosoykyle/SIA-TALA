<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DocumentRequestScopeRemovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_request_runtime_is_removed(): void
    {
        $this->assertFalse(Schema::hasTable('document_requests'));
        $this->assertFalse(Route::has('student.documents'));
        $this->assertFileDoesNotExist(app_path('Models/DocumentRequest.php'));
        $this->assertFileDoesNotExist(app_path('Actions/ServiceRequests/DocumentRequestLifecycleService.php'));
        $this->assertFileDoesNotExist(app_path('Jobs/ShippingFeeEnforcerJob.php'));
        $this->assertDirectoryDoesNotExist(app_path('Filament/Resources/DocumentRequests'));
        $this->assertFileDoesNotExist(resource_path('views/pages/student-hub/⚡documents.blade.php'));

        $scheduledDescriptions = collect(Schedule::events())
            ->pluck('description')
            ->filter()
            ->values()
            ->all();

        $this->assertNotContains('document-requests.shipping-fee-enforcer', $scheduledDescriptions);
    }

    public function test_service_request_permissions_no_longer_use_document_request_names(): void
    {
        $sources = [
            file_get_contents(database_path('seeders/DatabaseSeeder.php')),
            file_get_contents(app_path('Actions/ServiceRequests/ServiceRequestLifecycleService.php')),
            file_get_contents(app_path('Policies/ServiceRequestPolicy.php')),
        ];

        foreach ($sources as $source) {
            $this->assertIsString($source);
            $this->assertStringNotContainsString('manage-document-requests', $source);
            $this->assertStringNotContainsString('request-documents', $source);
        }

        $combinedSource = implode("\n", $sources);

        $this->assertStringContainsString('manage-service-requests', $combinedSource);
        $this->assertStringContainsString('submit-service-requests', $combinedSource);
    }
}
