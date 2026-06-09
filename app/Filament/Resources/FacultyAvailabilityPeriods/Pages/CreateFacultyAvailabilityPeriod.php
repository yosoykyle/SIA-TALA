<?php

namespace App\Filament\Resources\FacultyAvailabilityPeriods\Pages;

use App\Actions\Scheduling\FacultyAvailabilityService;
use App\Filament\Resources\FacultyAvailabilityPeriods\FacultyAvailabilityPeriodResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;

class CreateFacultyAvailabilityPeriod extends CreateRecord
{
    protected static string $resource = FacultyAvailabilityPeriodResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $actor = auth()->user();

        abort_unless($actor instanceof User, 403);

        return app(FacultyAvailabilityService::class)->preparePeriodData($data, $actor);
    }
}
