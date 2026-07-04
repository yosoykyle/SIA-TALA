<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCurriculumVersion extends ViewRecord
{
    protected static string $resource = CurriculumVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
