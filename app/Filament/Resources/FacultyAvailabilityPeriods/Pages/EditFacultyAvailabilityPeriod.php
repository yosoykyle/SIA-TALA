<?php

namespace App\Filament\Resources\FacultyAvailabilityPeriods\Pages;

use App\Actions\Scheduling\FacultyAvailabilityService;
use App\Filament\Resources\FacultyAvailabilityPeriods\FacultyAvailabilityPeriodResource;
use App\Models\FacultyAvailabilityPeriod;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditFacultyAvailabilityPeriod extends EditRecord
{
    protected static string $resource = FacultyAvailabilityPeriodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $actor = auth()->user();
        $record = $this->getRecord();

        abort_unless($actor instanceof User && $record instanceof FacultyAvailabilityPeriod, 403);

        return app(FacultyAvailabilityService::class)->preparePeriodData($data, $actor, $record);
    }
}
