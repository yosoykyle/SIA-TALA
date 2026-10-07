<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdmissionCycles extends ListRecords
{
    protected static string $resource = AdmissionCycleResource::class;

    public function getBreadcrumb(): string
    {
        return 'Admission cycles';
    }

    public function getBreadcrumbs(): array
    {
        return [
            ...(AdmissionApplicationResource::canAccess() ? [AdmissionApplicationResource::getUrl() => 'Admissions'] : []),
            'Admission cycles',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Prepare admission cycle')->icon('heroicon-o-plus'),
        ];
    }
}
