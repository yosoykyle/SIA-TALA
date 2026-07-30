<?php

namespace App\Filament\Resources\GraduationReviewBatches\Pages;

use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use App\Models\GraduationReviewBatch;
use Filament\Resources\Pages\CreateRecord;

class CreateGraduationReviewBatch extends CreateRecord
{
    protected static string $resource = GraduationReviewBatchResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['state'] = GraduationReviewBatch::StateOpen;
        $data['filter_summary'] = null;
        $data['closed_at'] = null;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var GraduationReviewBatch $batch */
        $batch = $this->getRecord();

        activity()
            ->performedOn($batch)
            ->causedBy(auth()->user())
            ->event('graduation_review_batch_created')
            ->withProperties([
                'name' => $batch->name,
                'academic_year_id' => $batch->academic_year_id,
                'term_id' => $batch->term_id,
                'state' => $batch->state,
            ])
            ->log('Graduation Review Batch created');
    }
}
