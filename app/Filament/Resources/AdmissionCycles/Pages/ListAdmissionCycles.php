<?php

namespace App\Filament\Resources\AdmissionCycles\Pages;

use App\Filament\Clusters\Admissions;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use Filament\Actions\Action;
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
            Action::make('applicationQueue')
                ->label('Application queue')
                ->icon('heroicon-o-inbox-stack')
                ->color('gray')
                ->outlined()
                ->visible(fn (): bool => AdmissionApplicationResource::canAccess())
                ->url(fn (): string => AdmissionApplicationResource::getUrl()),
            CreateAction::make()->label('Prepare admission cycle')->icon('heroicon-o-plus'),
        ];
    }
}
