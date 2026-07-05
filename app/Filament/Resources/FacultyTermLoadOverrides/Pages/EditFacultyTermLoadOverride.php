<?php

namespace App\Filament\Resources\FacultyTermLoadOverrides\Pages;

use App\Filament\Resources\FacultyTermLoadOverrides\FacultyTermLoadOverrideResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFacultyTermLoadOverride extends EditRecord
{
    protected static string $resource = FacultyTermLoadOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
