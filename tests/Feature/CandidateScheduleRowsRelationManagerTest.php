<?php

namespace Tests\Feature;

use Tests\TestCase;

class CandidateScheduleRowsRelationManagerTest extends TestCase
{
    public function test_schedule_generation_run_registers_draft_rows_review_relation(): void
    {
        $resource = $this->resourceSource('ScheduleGenerationRuns/ScheduleGenerationRunResource.php');
        $relationManager = $this->resourceSource('ScheduleGenerationRuns/RelationManagers/CandidateRowsRelationManager.php');
        $infolist = $this->resourceSource('ScheduleGenerationRuns/Schemas/ScheduleGenerationRunInfolist.php');

        $this->assertStringContainsString('CandidateRowsRelationManager::class', $resource);
        $this->assertStringContainsString('Draft Rows Review', $relationManager);
        $this->assertStringContainsString("Action::make('reviseDraftRow')", $relationManager);
        $this->assertStringContainsString('CandidateScheduleRowReviewService', $relationManager);
        $this->assertStringContainsString("Textarea::make('override_reason')", $relationManager);
        $this->assertStringContainsString('payloadSummary', $relationManager);
        $this->assertStringContainsString('draft_row_conflicts', $infolist);

        foreach ([
            'CreateAction::make()',
            'EditAction::make()',
            'DeleteAction::make()',
            'DeleteBulkAction::make()',
            'DissociateAction::make()',
            'AssociateAction::make()',
        ] as $forbiddenAction) {
            $this->assertStringNotContainsString($forbiddenAction, $relationManager);
        }
    }

    private function resourceSource(string $relativePath): string
    {
        $source = file_get_contents(app_path("Filament/Resources/{$relativePath}"));

        $this->assertIsString($source);

        return $source;
    }
}
