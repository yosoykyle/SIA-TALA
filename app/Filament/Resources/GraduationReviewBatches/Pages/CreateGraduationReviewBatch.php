<?php

namespace App\Filament\Resources\GraduationReviewBatches\Pages;

use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateGraduationReviewBatch extends CreateRecord
{
    protected static string $resource = GraduationReviewBatchResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
