<?php

namespace App\Filament\Resources\AdmissionApplications\Pages;

use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Models\AdmissionApplication;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAdmissionApplications extends ListRecords
{
    protected static string $resource = AdmissionApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'needs_review' => Tab::make('Needs review')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('application_state', AdmissionApplication::StateSubmitted)),
            'waiting_for_applicant' => Tab::make('Waiting for applicant')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('application_state', AdmissionApplication::StateActionNeeded)),
            'official_credentials' => Tab::make('Official credentials')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('application_state', AdmissionApplication::StateAdmitted)
                    ->whereNotIn('id', $this->readyApplicationIds())),
            'ready_for_enrollment' => Tab::make('Ready for enrollment')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('id', $this->readyApplicationIds())),
            'history' => Tab::make('History')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('application_state', [
                        AdmissionApplication::StateNotAdmitted,
                        AdmissionApplication::StateWithdrawn,
                    ])),
        ];
    }

    /** @return list<int> */
    private function readyApplicationIds(): array
    {
        return app(ReadyApplicantProjectionQuery::class)->readyApplicationIds()->all();
    }
}
