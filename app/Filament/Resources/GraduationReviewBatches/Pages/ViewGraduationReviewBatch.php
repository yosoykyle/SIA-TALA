<?php

namespace App\Filament\Resources\GraduationReviewBatches\Pages;

use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewGraduationReviewBatch extends ViewRecord
{
    protected static string $resource = GraduationReviewBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
