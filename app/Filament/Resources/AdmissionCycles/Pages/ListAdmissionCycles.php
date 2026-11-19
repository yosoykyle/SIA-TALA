<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Filament\Clusters\Admissions;
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

    /**
     * Linked to the Admissions dashboard, not the Application queue: this page's "Admissions"
     * crumb previously pointed at the queue list, leaving no way back to the dashboard (#80).
     *
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            ...(Admissions::canAccess() ? [Admissions::getUrl() => 'Admissions'] : []),
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
