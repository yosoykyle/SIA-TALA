<?php

namespace Tests\Feature;

use Tests\TestCase;

class ImportBatchFilamentControlledWorkflowTest extends TestCase
{
    public function test_import_batch_resource_exposes_controlled_upload_and_template_actions_without_generic_crud(): void
    {
        $resource = $this->resourceSource('ImportBatches/ImportBatchResource.php');
        $listPage = $this->resourceSource('ImportBatches/Pages/ListImportBatches.php');
        $table = $this->resourceSource('ImportBatches/Tables/ImportBatchesTable.php');
        $infolist = $this->resourceSource('ImportBatches/Schemas/ImportBatchInfolist.php');

        $this->assertStringContainsString('Import Batch Audit', $resource);
        $this->assertStringNotContainsString("CreateImportBatch::route('/create')", $resource);
        $this->assertStringNotContainsString("EditImportBatch::route('/{record}/edit')", $resource);
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ImportBatches/Pages/CreateImportBatch.php'));
        $this->assertFileDoesNotExist(app_path('Filament/Resources/ImportBatches/Pages/EditImportBatch.php'));
        $this->assertStringNotContainsString('function form(', $resource);

        $this->assertStringContainsString("Action::make('downloadCurriculumTemplate')", $listPage);
        $this->assertStringContainsString("Action::make('uploadCurriculumImport')", $listPage);
        $this->assertStringContainsString("FileUpload::make('file')", $listPage);
        $this->assertStringContainsString('acceptedFileTypes', $listPage);
        $this->assertStringContainsString('CurriculumImportService', $listPage);
        $this->assertStringContainsString('CurriculumImportTemplate', $listPage);
        $this->assertStringContainsString("->directory('imports/curriculum/uploads')", $listPage);

        $this->assertStringContainsString('commitAction', $table);
        $this->assertStringContainsString('ImportBatchLifecycleService', $table);
        $this->assertStringNotContainsString('DB::transaction', $table);
        $this->assertStringNotContainsString("'status' => 'committed'", $table);

        $this->assertStringContainsString('importer.name', $infolist);
        $this->assertStringContainsString('committer.name', $infolist);
        $this->assertStringContainsString('preview_summary', $infolist);
        $this->assertStringNotContainsString("TextEntry::make('file_path')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('imported_by')", $infolist);
        $this->assertStringNotContainsString("TextEntry::make('committed_by')", $infolist);
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
