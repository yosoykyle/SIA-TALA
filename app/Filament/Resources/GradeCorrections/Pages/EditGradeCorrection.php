<?php

namespace App\Filament\Resources\GradeCorrections\Pages;

use App\Filament\Resources\GradeCorrections\GradeCorrectionResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditGradeCorrection extends EditRecord
{
    protected static string $resource = GradeCorrectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
