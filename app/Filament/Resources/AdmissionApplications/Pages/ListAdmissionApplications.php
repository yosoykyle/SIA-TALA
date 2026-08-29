<?php

namespace App\Filament\Resources\AdmissionApplications\Pages;

use App\Filament\Pages\AssistedAdmissionApplication;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionApplication;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAdmissionApplications extends ListRecords
{
    protected static string $resource = AdmissionApplicationResource::class;

    /** @var list<int>|null */
    private ?array $readyApplicationIdCache = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepareAssistedDraft')
                ->label('Prepare assisted Draft')
                ->icon('heroicon-o-user-plus')
                ->modalDescription('Select an existing active Applicant account. The Registrar may prepare an unsubmitted Draft only; the Applicant remains the owner and must submit it.')
                ->schema([
                    Select::make('applicant_id')
                        ->label('Applicant account')
                        ->options(fn (): array => User::query()
                            ->where('status', User::StatusActive)
                            ->whereNotNull('email_verified_at')
                            ->whereHas('roles', fn (Builder $query): Builder => $query->where('name', 'applicant'))
                            ->orderBy('email')
                            ->pluck('email', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                ])
                ->visible(fn (): bool => AssistedAdmissionApplication::canAccess())
                ->action(fn (array $data): mixed => $this->redirect(AssistedAdmissionApplication::getUrl([
                    'applicant' => (int) $data['applicant_id'],
                ]))),
            Action::make('admissionCycles')
                ->label('Manage admission cycles')
                ->color('gray')
                ->url(fn (): string => AdmissionCycleResource::getUrl())
                ->visible(fn (): bool => AdmissionCycleResource::canAccess()),
        ];
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
        return $this->readyApplicationIdCache ??= app(ReadyApplicantProjectionQuery::class)
            ->readyApplicationIds()
            ->all();
    }
}
