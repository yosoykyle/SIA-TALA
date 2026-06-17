<?php

namespace App\Filament\Resources\Curriculums\Pages;

use App\Filament\Resources\Curriculums\CurriculumResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCurriculum extends EditRecord
{
    protected static string $resource = CurriculumResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
