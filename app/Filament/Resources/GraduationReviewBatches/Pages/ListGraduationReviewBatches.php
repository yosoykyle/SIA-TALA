<?php

namespace App\Filament\Resources\GraduationReviewBatches\Pages;

use App\Filament\Resources\GraduationReviewBatches\GraduationReviewBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListGraduationReviewBatches extends ListRecords
{
    protected static string $resource = GraduationReviewBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
