<?php

namespace App\Filament\Resources\ApplicantIntakes\Pages;

use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditApplicantIntake extends EditRecord
{
    protected static string $resource = ApplicantIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
