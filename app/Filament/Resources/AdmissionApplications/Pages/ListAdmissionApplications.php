<?php

namespace App\Filament\Resources\AdmissionApplications\Pages;

use App\Filament\Pages\AssistedAdmissionApplication;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Models\AdmissionApplication;
use App\Queries\Admissions\AssistedDraftApplicantQuery;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ListAdmissionApplications extends ListRecords
{
    protected static string $resource = AdmissionApplicationResource::class;

    protected array $extraBodyAttributes = ['class' => 'tala-admissions-queue'];

    public static function canAccess(array $parameters = []): bool
    {
        return static::getResource()::canViewAny();
    }

    protected function authorizeAccess(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @var list<int>|null */
    private ?array $readyApplicationIdCache = null;

    public function getBreadcrumb(): string
    {
        return 'Application queue';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('prepareAssistedDraft')
                ->label('Prepare assisted draft')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->modalSubmitActionLabel('Continue')
                ->modalDescription('Find an active, verified Applicant with an existing draft or an available new intake. The Applicant remains the owner and must submit it.')
                ->schema([
                    Select::make('applicant_id')
                        ->label('Applicant account')
                        ->placeholder('Find an eligible Applicant')
                        ->getSearchResultsUsing(fn (string $search): array => app(AssistedDraftApplicantQuery::class)->eligible()
                            ->where(fn (Builder $query): Builder => $query->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                            ->orderBy('email')
                            ->limit(50)
                            ->pluck('email', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => app(AssistedDraftApplicantQuery::class)->eligible()->whereKey($value)->value('email'))
                        ->searchable()
                        ->helperText('Search by name or email. Only available drafts or new intakes appear. Applicants complete their own corrections and submit.')
                        ->noSearchResultsMessage('No eligible Applicant found. Check account verification and intake availability; submitted work stays in the application queue.')
                        ->required(),
                ])
                ->visible(fn (): bool => AssistedAdmissionApplication::canAccess())
                ->action(function (array $data): void {
                    if (! app(AssistedDraftApplicantQuery::class)->eligible()->whereKey($data['applicant_id'])->exists()) {
                        throw ValidationException::withMessages(['applicant_id' => 'This Applicant is no longer available for assisted draft preparation. Search again for an eligible account.']);
                    }

                    $this->redirect(AssistedAdmissionApplication::getUrl(['applicant' => (int) $data['applicant_id']]));
                }),
        ];
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        return [
            'needs_review' => Tab::make('Needs review')
                ->badge(fn (): ?int => $this->queueCount(AdmissionApplication::StateSubmitted))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('application_state', AdmissionApplication::StateSubmitted)),
            'waiting_for_applicant' => Tab::make('Waiting for applicant')
                ->badge(fn (): ?int => $this->queueCount(AdmissionApplication::StateActionNeeded))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('application_state', AdmissionApplication::StateActionNeeded)),
            'official_credentials' => Tab::make('Registrar clearance')
                ->badge(fn (): ?int => AdmissionApplicationResource::getEloquentQuery()->where('application_state', AdmissionApplication::StateAdmitted)->whereNotIn('id', $this->readyApplicationIds())->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('application_state', AdmissionApplication::StateAdmitted)
                    ->whereNotIn('id', $this->readyApplicationIds())),
            'ready_for_enrollment' => Tab::make('Ready for enrollment')
                ->badge(fn (): ?int => AdmissionApplicationResource::getEloquentQuery()->whereIn('id', $this->readyApplicationIds())->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('id', $this->readyApplicationIds())),
            'history' => Tab::make('Closed applications')
                ->badge(fn (): ?int => AdmissionApplicationResource::getEloquentQuery()->whereIn('application_state', [AdmissionApplication::StateNotAdmitted, AdmissionApplication::StateWithdrawn])->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('application_state', [
                        AdmissionApplication::StateNotAdmitted,
                        AdmissionApplication::StateWithdrawn,
                    ])),
        ];
    }

    public function getSubheading(): ?string
    {
        if ($this->activeTab === 'history') {
            return 'Terminal admission applications (withdrawn or not admitted). For an individual application audit trail, view Record history within that application.';
        }

        return null;
    }

    private function queueCount(string $state): ?int
    {
        return AdmissionApplicationResource::getEloquentQuery()->where('application_state', $state)->count() ?: null;
    }

    /** @return list<int> */
    private function readyApplicationIds(): array
    {
        return $this->readyApplicationIdCache ??= app(ReadyApplicantProjectionQuery::class)
            ->readyApplicationIds()
            ->all();
    }
}
