<?php

namespace App\Filament\Resources\AdmissionApplications\Pages;

use App\Filament\Pages\AssistedAdmissionApplication;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Models\AdmissionApplication;
use App\Models\User;
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
        $applicantLabel = fn (User $applicant): string => $applicant->getFilamentName().' · '.$applicant->email;
        $applicationLabel = fn (AdmissionApplication $application): string => $application->user->getFilamentName().' · '.$application->application_reference.' · '.$application->application_state;
        $referencedApplication = function (mixed $value): ?AdmissionApplication {
            if (! is_string($value) || ! preg_match('/^application:(\d+)$/D', $value, $matches)) {
                return null;
            }

            return AdmissionApplicationResource::getEloquentQuery()->with('user')
                ->whereNotNull('application_reference')->find($matches[1]);
        };

        return [
            Action::make('prepareAssistedDraft')
                ->label('Prepare assisted draft')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->modalSubmitActionLabel('Continue')
                ->modalDescription('Find an Applicant by name, email or application reference. Available drafts open for assistance; submitted applications open for Registrar review. Applicants submit their own drafts.')
                ->schema([
                    Select::make('applicant_id')
                        ->label('Applicant or application')
                        ->placeholder('Find an Applicant or application')
                        ->getSearchResultsUsing(function (string $search) use ($applicantLabel, $applicationLabel): array {
                            $search = trim($search);

                            if (str_starts_with(strtoupper($search), 'APP-')) {
                                return AdmissionApplicationResource::getEloquentQuery()->with('user')
                                    ->where('application_reference', 'like', $search.'%')
                                    ->latest('id')->limit(50)->get()
                                    ->mapWithKeys(fn (AdmissionApplication $application): array => ['application:'.$application->id => $applicationLabel($application)])
                                    ->all();
                            }

                            return app(AssistedDraftApplicantQuery::class)->eligible()
                                ->where(fn (Builder $query): Builder => $query->where('email', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                                ->orderBy('email')->limit(50)->get()
                                ->mapWithKeys(fn (User $applicant): array => [$applicant->id => $applicantLabel($applicant)])
                                ->all();
                        })
                        ->getOptionLabelUsing(function (mixed $value) use ($applicantLabel, $applicationLabel, $referencedApplication): ?string {
                            if ($application = $referencedApplication($value)) {
                                return $applicationLabel($application);
                            }

                            $applicant = app(AssistedDraftApplicantQuery::class)->eligible()->find($value);

                            return $applicant instanceof User ? $applicantLabel($applicant) : null;
                        })
                        ->searchable()
                        ->helperText('Use the APP-… reference from the application receipt to find a submitted case. Name and email searches show Applicants available for draft assistance.')
                        ->noSearchResultsMessage('No match found. Check the application reference, or use the name or email of an eligible Applicant.')
                        ->required(),
                ])
                ->visible(fn (): bool => AssistedAdmissionApplication::canAccess())
                ->action(function (array $data) use ($referencedApplication): void {
                    $application = $referencedApplication($data['applicant_id']);

                    if ($application instanceof AdmissionApplication) {
                        if (! AdmissionApplicationResource::canView($application)) {
                            throw ValidationException::withMessages(['applicant_id' => 'This application is unavailable for Registrar review. Search again.']);
                        }

                        if ($application->application_state !== AdmissionApplication::StateDraft) {
                            $this->redirect(AdmissionApplicationResource::getUrl('view', ['record' => $application]));

                            return;
                        }

                        if (! app(AssistedDraftApplicantQuery::class)->eligible($application->id)->whereKey($application->user_id)->exists()) {
                            throw ValidationException::withMessages(['applicant_id' => 'This draft is no longer available for assistance. Search again for an eligible account.']);
                        }

                        $this->redirect(AssistedAdmissionApplication::getUrl(['applicant' => $application->user_id, 'application' => $application->id]));

                        return;
                    }

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
