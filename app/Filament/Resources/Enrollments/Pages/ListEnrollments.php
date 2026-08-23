<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\FinanceExport;
use App\Models\PaymentEvidenceVersion;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class ListEnrollments extends ListRecords
{
    protected static string $resource = EnrollmentResource::class;

    public function getTitle(): string
    {
        return $this->isAccounting() ? 'Student Accounts' : parent::getTitle();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        if (! $this->isAccounting()) {
            return [];
        }

        return [
            'accounts' => Tab::make('Accounts')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->whereHas('termAccount'),
            ),
            'payment_exceptions' => Tab::make('Payment Exceptions')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->whereHas(
                    'termAccount.latestPaymentEvidenceVersion',
                    fn (Builder $query): Builder => $query->where('state', PaymentEvidenceVersion::StateSubmitted),
                ),
            ),
            'tor_clearance' => Tab::make('TOR Clearance')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->whereHas('studentProfile.transcriptRequests'),
            ),
        ];
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        if ($this->isAccounting()) {
            return [
                Action::make('openTorClearance')
                    ->label('Open TOR Clearance')
                    ->icon('heroicon-o-document-check')
                    ->url(TranscriptRequestResource::getUrl('index'))
                    ->visible(fn (): bool => ($this->activeTab ?? 'accounts') === 'tor_clearance'),
                Action::make('exportAccountStatus')
                    ->label('Export Account Status')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->schema([
                        Textarea::make('purpose')
                            ->label('Purpose')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->visible(fn (): bool => ($this->activeTab ?? 'accounts') === 'accounts')
                    ->action(function (array $data): void {
                        /** @var Collection<int, Enrollment> $enrollments */
                        $enrollments = $this->getFilteredSortedTableQuery()->limit(10001)->get();
                        $export = app(CreateContextualFinanceExport::class)->createAccountStatus(
                            auth()->user(),
                            (string) $data['purpose'],
                            $enrollments,
                            [
                                'active_tab' => $this->activeTab ?? 'accounts',
                                'filters' => $this->tableFilters ?? [],
                                'search' => $this->getTableSearch(),
                                'sort' => [
                                    'column' => $this->getTableSortColumn(),
                                    'direction' => $this->getTableSortDirection(),
                                ],
                            ],
                        );

                        if ($export->outcome === FinanceExport::OutcomeNoRows) {
                            Notification::make()->title('No matching account rows')->info()->send();

                            return;
                        }

                        $this->redirect(route('finance.exports.download', $export));
                    }),
            ];
        }

        return [
            Action::make('readyApplicants')
                ->label('Ready applicants')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->modalHeading('Ready applicants from Admissions')
                ->modalDescription('Read-only Clinic 4 visibility. No Registration Case, Student, enrollment, placement, or assessment has been created.')
                ->modalContent(fn () => view('filament.admin.enrollments.ready-applicants', [
                    'applications' => $this->readyApplicants(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false),
            Action::make('startContinuingEnrollment')
                ->label('Start Continuing Enrollment')
                ->icon('heroicon-o-plus-circle')
                ->labeledFrom('md')
                ->tooltip('Start continuing enrollment')
                ->visible(fn (): bool => auth()->user()?->hasAnyRole([
                    User::StaffRoleRegistrar,
                    User::StaffRoleSystemSuperAdmin,
                ]) ?? false)
                ->schema([
                    Select::make('student_profile_id')
                        ->label('Student')
                        ->options(fn (): array => StudentProfile::query()
                            ->with('program')
                            ->orderBy('student_number')
                            ->get()
                            ->mapWithKeys(fn (StudentProfile $profile): array => [
                                $profile->id => collect([
                                    $profile->student_number,
                                    $profile->last_name,
                                    $profile->first_name,
                                    $profile->program?->code,
                                ])->filter()->implode(' - '),
                            ])
                            ->all())
                        ->searchable()
                        ->required(),
                    Select::make('term_id')
                        ->label('Enrollment term')
                        ->options(fn (): array => Term::query()
                            ->where('state', Term::StateActive)
                            ->orderByDesc('starts_on')
                            ->get()
                            ->mapWithKeys(fn (Term $term): array => [$term->id => $term->label])
                            ->all())
                        ->required(),
                    Select::make('selection_basis')
                        ->label('Registration basis')
                        ->options([
                            Enrollment::SelectionStandardCurriculum => 'Standard Curriculum',
                            Enrollment::SelectionIndividuallyAdvised => 'Individually Advised',
                        ])
                        ->default(Enrollment::SelectionStandardCurriculum)
                        ->required(),
                    Select::make('start_method')
                        ->label('Start authority')
                        ->options([
                            'RegistrarAssisted' => 'Registrar-assisted within the registration window',
                            'LateAuthority' => 'Authorized late start outside the registration window',
                        ])
                        ->default('RegistrarAssisted')
                        ->required(),
                    TextInput::make('authority_reference')
                        ->label('Assisted or late authority reference')
                        ->helperText('Record the learner channel/evidence or the explicit late-start authority.')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    $profile = StudentProfile::query()->find($data['student_profile_id']);
                    $term = Term::query()->find($data['term_id']);

                    if (! $actor instanceof User
                        || ! $profile instanceof StudentProfile
                        || ! $term instanceof Term) {
                        return;
                    }

                    try {
                        $enrollment = app(StartRegistrationCase::class)->forContinuingStudent(
                            $profile,
                            $term,
                            $actor,
                            (string) $data['selection_basis'],
                            (string) $data['start_method'],
                            (string) $data['authority_reference'],
                        );

                        if (! $enrollment->wasRecentlyCreated) {
                            Notification::make()
                                ->title('Enrollment already exists')
                                ->body('No duplicate was created. Open the existing record from the enrollment list to continue its current workflow.')
                                ->info()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Enrollment started')
                            ->body('The exact-Term Registration Case is ready for proposal and placement review.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Enrollment not started')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    private function isAccounting(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleAccounting) ?? false;
    }

    /** @return Collection<int, AdmissionApplication> */
    private function readyApplicants(): Collection
    {
        $query = app(ReadyApplicantProjectionQuery::class);

        return AdmissionApplication::query()
            ->canonical()
            ->where('application_state', AdmissionApplication::StateAdmitted)
            ->with(['user', 'program', 'admissionCycle', 'decisions', 'credentialResults.requirement', 'currentSubmissionVersion.requirementSet.requirements'])
            ->get()
            ->filter(fn (AdmissionApplication $application): bool => $query->forApplication($application)['ready'])
            ->values();
    }
}
