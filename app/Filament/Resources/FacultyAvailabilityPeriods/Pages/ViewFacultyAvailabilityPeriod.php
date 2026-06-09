<?php

namespace App\Filament\Resources\FacultyAvailabilityPeriods\Pages;

use App\Filament\Resources\FacultyAvailabilityPeriods\FacultyAvailabilityPeriodResource;
use App\Models\FacultyAvailabilityPeriod;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFacultyAvailabilityPeriod extends ViewRecord
{
    protected static string $resource = FacultyAvailabilityPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->visible(fn (FacultyAvailabilityPeriod $record): bool => ! $record->isLocked()),
        ];
    }
}
