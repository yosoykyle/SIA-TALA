<?php

namespace App\Filament\Resources\CurriculumVersions\Pages;

use App\Filament\Resources\CurriculumVersions\CurriculumVersionResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCurriculumVersion extends EditRecord
{
    protected static string $resource = CurriculumVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
