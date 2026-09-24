<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\TranscriptRequests\TranscriptRequestResource;
use App\Models\AdmissionApplication;
use App\Models\Enrollment;
use App\Models\EnrollmentSeatReservation;
use App\Models\FinanceExport;
use App\Models\OperationalEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvidenceVersion;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class ListEnrollments extends ListRecords
{
    protected static string $resource = EnrollmentResource::class;

    public function getTitle(): string
    {
        if ($this->isAccounting()) {
            return 'Student Accounts';
        }

        return $this->isRegistrar() ? 'Students & Enrollment' : parent::getTitle();
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        $tabAttributes = ['style' => 'padding-left: 8px; padding-right: 8px; font-size: 12.5px;'];

        if ($this->isRegistrar()) {
            return [
                'ready_to_prepare' => Tab::make('Ready to prepare')
                    ->extraAttributes($tabAttributes)
                    ->modifyQueryUsing(
                        fn (Builder $query): Builder => $query
                            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
                            ->whereDoesntHave('currentProposalVersion'),
                    ),
                'waiting_for_learner' => Tab::make('Waiting for learner')
                    ->extraAttributes($tabAttributes)
                    ->modifyQueryUsing(
                        fn (Builder $query): Builder => $query->whereHas(
                            'currentProposalVersion',
                            fn (Builder $query): Builder => $query->where('state', RegistrationProposalVersion::StateIssued),
                        ),
                    ),
                'placement_shortages' => Tab::make('Placement and shortages')
                    ->extraAttributes($tabAttributes)
                    ->modifyQueryUsing(
                        fn (Builder $query): Builder => $query->whereHas(
                            'seatReservations',
                            fn (Builder $query): Builder => $query->whereIn('status', [EnrollmentSeatReservation::StatusPending, EnrollmentSeatReservation::StatusReleased]),
                        ),
                    ),
                'finance_pending' => Tab::make('Finance pending')
                    ->extraAttributes($tabAttributes)
                    ->modifyQueryUsing(
                        fn (Builder $query): Builder => $query->where('canonical_outcome', Enrollment::OutcomeInProgress)
                            ->where(function (Builder $query): void {
                                $query->whereDoesntHave('termAccount')
                                    ->orWhereHas('termAccount', fn (Builder $query): Builder => $query->where('state', '!=', TermAccount::StateCleared));
                            }),
                    ),
                'ready_to_finalize' => Tab::make('Ready to finalize')
                    ->extraAttributes($tabAttributes)
                    ->modifyQueryUsing(
                        fn (Builder $query): Builder => $query
                            ->where('canonical_outcome', Enrollment::OutcomeInProgress)
                            ->whereHas('currentProposalVersion', fn (Builder $query): Builder => $query->where('state', RegistrationProposalVersion::StateConfirmed))
                            ->whereHas('termAccount', fn (Builder $query): Builder => $query->where('state', TermAccount::StateCleared)),
                    ),
                'adjustments_drops' => Tab::make('Adjustments and Drops')
                    ->extraAttributes($tabAttributes)
                    ->modifyQueryUsing(
                        fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                            $query->whereHas('proposalVersions', fn (Builder $query): Builder => $query->where('purpose', RegistrationProposalVersion::PurposeAdjustment))
                                ->orWhereHas('courseEnrollments', fn (Builder $query): Builder => $query->where('status', 'dropped'));
                        }),
                    ),
                'official_history' => Tab::make('Official and history')
                    ->extraAttributes($tabAttributes)
                    ->modifyQueryUsing(
                        fn (Builder $query): Builder => $query->where('canonical_outcome', '!=', Enrollment::OutcomeInProgress),
                    ),
            ];
        }

        if (! $this->isAccounting()) {
            return [];
        }

        return [
            'accounts' => Tab::make('Accounts')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->whereHas('termAccount'),
            ),
            'payment_exceptions' => Tab::make('Payment Exceptions')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                    $query->whereHas(
                        'termAccount.latestPaymentEvidenceVersion',
                        fn (Builder $query): Builder => $query->where('state', PaymentEvidenceVersion::StateSubmitted),
                    )->orWhereHas('termAccount.paymentAttempts', function (Builder $query): void {
                        $query->where('status', PaymentAttempt::StatusReviewRequired)
                            ->orWhereExists(function ($events): void {
                                $events->selectRaw('1')
                                    ->from('operational_events')
                                    ->whereColumn('operational_events.related_record_id', 'payment_attempts.id')
                                    ->where('operational_events.related_record_type', PaymentAttempt::class)
                                    ->where('operational_events.integration', OperationalEvent::IntegrationPayMongo)
                                    ->whereIn('operational_events.status', [
                                        OperationalEvent::StatusReviewRequired,
                                        OperationalEvent::StatusFailed,
                                    ]);
                            });
                    })->orWhereHas(
                        'termAccount.payments',
                        fn (Builder $query): Builder => $query->where('evidence_status', 'under_review'),
                    );
                }),
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
            Action::make('startReadyApplicantRegistration')
                ->label('Start registration for Ready Applicant')
                ->icon('heroicon-o-user-plus')
                ->labeledFrom('md')
                ->tooltip('Start registration for ready applicant')
                ->authorize(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                ->fillForm(function (array $arguments): array {
                    $applicationId = $arguments['application_id'] ?? null;
                    $termId = $arguments['term_id'] ?? null;

                    if ($applicationId && ! $termId) {
                        $app = AdmissionApplication::query()->find($applicationId);
                        $termId = $app?->term_id;
                    }

                    return [
                        'application_id' => $applicationId,
                        'term_id' => $termId,
                    ];
                })
                ->schema([
                    Select::make('application_id')
                        ->label('Ready applicant')
                        ->options(function (): array {
                            $applications = $this->readyApplicants();
                            $applicationIds = $applications->pluck('id')->filter()->all();
                            $userIds = $applications->pluck('user_id')->filter()->all();
                            $termIds = $applications->pluck('term_id')->filter()->all();

                            $existingCases = Enrollment::query()
                                ->whereIn('term_id', $termIds)
                                ->where(function (Builder $query) use ($applicationIds, $userIds): void {
                                    $query->whereIn('admission_application_id', $applicationIds);
                                    if (! empty($userIds)) {
                                        $query->orWhereIn('credential_user_id', $userIds);
                                    }
                                })
                                ->get();

                            return $applications
                                ->reject(function (AdmissionApplication $application) use ($existingCases): bool {
                                    return $existingCases->contains(function (Enrollment $case) use ($application): bool {
                                        if ((int) $case->term_id !== (int) $application->term_id) {
                                            return false;
                                        }

                                        return (int) $case->admission_application_id === (int) $application->id
                                            || ($application->user_id && (int) $case->credential_user_id === (int) $application->user_id);
                                    });
                                })
                                ->mapWithKeys(fn (AdmissionApplication $application): array => [
                                    $application->id => collect([
                                        $application->application_reference,
                                        $application->last_name.', '.$application->first_name,
                                        $application->program?->code,
                                        $application->term?->label,
                                    ])->filter()->implode(' · '),
                                ])
                                ->all();
                        })
                        ->searchable()
                        ->live()
                        ->helperText('Only ready applicants without an existing registration case for the exact term are listed. To access an existing case, use "Open case" in the Ready Applicants list.')
                        ->afterStateUpdated(function (Set $set, ?int $state): void {
                            if (! $state) {
                                return;
                            }

                            $app = AdmissionApplication::query()->find($state);
                            if ($app instanceof AdmissionApplication) {
                                $set('term_id', $app->term_id);
                            }
                        })
                        ->required(),
                    Select::make('term_id')
                        ->label('Enrollment term')
                        ->options(fn (): array => Term::query()
                            ->where('state', Term::StateActive)
                            ->orderByDesc('starts_on')
                            ->get()
                            ->mapWithKeys(fn (Term $term): array => [$term->id => $term->label])
                            ->all())
                        ->helperText('Must match the exact term derived from the applicant admission readiness.')
                        ->required(),
                    TextInput::make('authority_reference')
                        ->label('Assisted authority reference')
                        ->helperText('Record the intake channel or authorization reference (e.g., in-person intake, verified learner email).')
                        ->required()
                        ->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $actor = auth()->user();
                    if (! $actor instanceof User || ! $actor->hasRole(User::StaffRoleRegistrar)) {
                        abort(403, 'Unauthorized.');
                    }

                    $application = AdmissionApplication::query()->find($data['application_id']);
                    $term = Term::query()->find($data['term_id']);

                    if (! $application instanceof AdmissionApplication || ! $term instanceof Term) {
                        Notification::make()
                            ->title('Registration not started')
                            ->body('Invalid applicant or term selected.')
                            ->danger()
                            ->send();

                        return;
                    }

                    if ((int) $application->term_id !== (int) $term->id) {
                        Notification::make()
                            ->title('Registration not started')
                            ->body('The selected term does not match the exact term derived for this ready applicant.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $existingCase = Enrollment::query()
                        ->where('term_id', $term->id)
                        ->where(function (Builder $query) use ($application): void {
                            $query->where('admission_application_id', $application->id);
                            if ($application->user_id) {
                                $query->orWhere('credential_user_id', $application->user_id);
                            }
                        })
                        ->first();

                    if ($existingCase instanceof Enrollment) {
                        Notification::make()
                            ->title('Registration case already exists')
                            ->body('No duplicate was created. Opening the existing case to continue its current workflow.')
                            ->info()
                            ->send();

                        $this->redirect(EnrollmentResource::getUrl('view', ['record' => $existingCase]));

                        return;
                    }

                    try {
                        $enrollment = app(StartRegistrationCase::class)->forReadyApplicant(
                            $application,
                            $term,
                            $actor,
                            'RegistrarAssisted',
                            (string) $data['authority_reference'],
                        );

                        Notification::make()
                            ->title('Registration Case started')
                            ->body('The exact-Term Registration Case is ready for proposal and placement review.')
                            ->success()
                            ->send();

                        $this->redirect(EnrollmentResource::getUrl('view', ['record' => $enrollment]));
                    } catch (CalendarGateViolation $exception) {
                        Notification::make()
                            ->title('Registration not started')
                            ->body('The enrollment window for this term is not currently open.')
                            ->warning()
                            ->send();
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Registration not started')
                            ->body(collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
                            ->danger()
                            ->send();
                    } catch (AuthorizationException $exception) {
                        Notification::make()
                            ->title('Registration not started')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    } catch (Throwable $exception) {
                        report($exception);

                        Notification::make()
                            ->title('Registration not started')
                            ->body('An unexpected error occurred while starting registration. Please try again or contact system support.')
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('readyApplicants')
                ->label('Ready applicants')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->modalHeading('Ready applicants from Admissions')
                ->modalDescription('Review ready applicants from Admissions. Start registration or open an existing exact-Term Registration Case.')
                ->authorize(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
                ->modalContent(function (): View {
                    if (! $this->isRegistrar()) {
                        abort(403, 'Unauthorized.');
                    }

                    $applications = $this->readyApplicants();
                    $applicationIds = $applications->pluck('id')->filter()->all();
                    $userIds = $applications->pluck('user_id')->filter()->all();
                    $termIds = $applications->pluck('term_id')->filter()->all();

                    $existingCases = Enrollment::query()
                        ->whereIn('term_id', $termIds)
                        ->where(function (Builder $query) use ($applicationIds, $userIds): void {
                            $query->whereIn('admission_application_id', $applicationIds);
                            if (! empty($userIds)) {
                                $query->orWhereIn('credential_user_id', $userIds);
                            }
                        })
                        ->get();

                    $casesMap = $applications->mapWithKeys(function (AdmissionApplication $app) use ($existingCases): array {
                        $case = $existingCases->first(function (Enrollment $e) use ($app): bool {
                            if ((int) $e->term_id !== (int) $app->term_id) {
                                return false;
                            }

                            return (int) $e->admission_application_id === (int) $app->id
                                || ($app->user_id && (int) $e->credential_user_id === (int) $app->user_id);
                        });

                        return [$app->id => $case];
                    });

                    return view('filament.admin.enrollments.ready-applicants', [
                        'applications' => $applications,
                        'casesMap' => $casesMap,
                    ]);
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),
            Action::make('startContinuingEnrollment')
                ->label('Start Continuing Enrollment')
                ->icon('heroicon-o-plus-circle')
                ->labeledFrom('md')
                ->tooltip('Start continuing enrollment')
                ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
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
                    Select::make('start_method')
                        ->label('Start authority')
                        ->options([
                            'RegistrarAssisted' => 'Registrar-assisted within the registration window',
                            'LateAuthority' => 'Authorized late start outside the registration window',
                        ])
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

    private function isRegistrar(): bool
    {
        return auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false;
    }

    public function startRegistrationForApplicant(int $applicationId, ?int $termId = null): void
    {
        if (! $this->isRegistrar()) {
            abort(403, 'Unauthorized.');
        }

        $this->replaceMountedAction('startReadyApplicantRegistration', [
            'application_id' => $applicationId,
            'term_id' => $termId,
        ]);
    }

    /** @return Collection<int, AdmissionApplication> */
    private function readyApplicants(): Collection
    {
        if (! $this->isRegistrar()) {
            abort(403, 'Unauthorized.');
        }

        $query = app(ReadyApplicantProjectionQuery::class);

        return AdmissionApplication::query()
            ->canonical()
            ->where('application_state', AdmissionApplication::StateAdmitted)
            ->with(['user', 'program', 'term', 'admissionCycle', 'decisions', 'credentialResults.requirement', 'currentSubmissionVersion.requirementSet.requirements'])
            ->get()
            ->filter(fn (AdmissionApplication $application): bool => $query->forApplication($application)['ready'])
            ->values();
    }
}
