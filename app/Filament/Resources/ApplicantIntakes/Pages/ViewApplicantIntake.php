<?php

namespace App\Filament\Resources\ApplicantIntakes\Pages;

use App\Filament\Resources\ApplicantIntakes\ApplicantIntakeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewApplicantIntake extends ViewRecord
{
    protected static string $resource = ApplicantIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
