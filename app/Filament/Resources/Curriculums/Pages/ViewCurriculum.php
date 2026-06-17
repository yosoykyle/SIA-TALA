<?php

namespace App\Filament\Resources\Curriculums\Pages;

use App\Filament\Resources\Curriculums\CurriculumResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCurriculum extends ViewRecord
{
    protected static string $resource = CurriculumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
