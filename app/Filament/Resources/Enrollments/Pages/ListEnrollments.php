<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Enrollment\RegistrationWindowApplicability;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\IndividualAssessmentSources;
use App\Actions\Finance\TermAccountProjection;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Models\AdmissionApplication;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FinanceExport;
use App\Models\OperationalEvent;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

class ListEnrollments extends ListRecords
{
    protected static string $resource = EnrollmentResource::class;

    #[Locked]
    public bool $financeExportFailed = false;

    #[Locked]
    public ?int $readyApplicantStartId = null;

    public string $readyApplicantSearch = '';

    #[Url]
    public ?int $oversightTermId = null;

    private function isHeadReader(): bool
    {
        return (auth()->user()?->hasRole(User::StaffRoleAcademicHead) ?? false)
            && ! $this->isRegistrar() && ! $this->isAccounting();
    }

    public function getExtraBodyAttributes(): array
    {
        return $this->isHeadReader() ? ['class' => 'tala-head-enrollments-page'] : parent::getExtraBodyAttributes();
    }

    /** @var list<array{case:string,learner:string,result:string,recovery:string,url:string}> */
    public array $finalizationResults = [];

    /** @var array<string, list<int>>|null */
    private ?array $currentWorkIds = null;

    /** @var array<string, list<int>>|null */
    private ?array $accountingWorkIds = null;

    /** @return list<int> */
    private function accountingCaseIds(string $kind): array
    {
        if ($this->accountingWorkIds === null) {
            $this->accountingWorkIds = ['assessment' => [], 'proof' => [], 'provider' => []];
            $cases = Enrollment::query()->with(['termAccount.assessments', 'termAccount.paymentEvidenceVersions'])
                ->where(fn (Builder $query): Builder => $query->whereHas('termAccount')->orWhere('canonical_outcome', Enrollment::OutcomeInProgress))->get();
            foreach ($cases as $case) {
                $assessment = $case->termAccount?->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();
                $sources = app(IndividualAssessmentSources::class)->forEnrollment($case);
                $position = $case->termAccount !== null ? app(TermAccountProjection::class)->forAccount($case->termAccount) : null;
                if (($case->canonical_outcome === Enrollment::OutcomeInProgress && ($assessment === null || (int) $assessment->source_proposal_version_id !== (int) $case->current_proposal_version_id))
                    || $sources['course_drop'] !== null
                    || ($sources['adjustment'] && (int) $assessment?->source_proposal_version_id !== (int) $case->current_proposal_version_id)
                    || ($position['reconciliation_required'] ?? false)) {
                    $this->accountingWorkIds['assessment'][] = (int) $case->id;
                }
                if ($case->termAccount?->paymentEvidenceVersions->contains('state', PaymentEvidenceVersion::StateSubmitted)) {
                    $this->accountingWorkIds['proof'][] = (int) $case->id;
                }
            }
            $this->accountingWorkIds['provider'] = Enrollment::query()->whereHas('termAccount.paymentAttempts', fn (Builder $query): Builder => $query
                ->where('status', PaymentAttempt::StatusReviewRequired)
                ->orWhereExists(function ($events): void {
                    $events->selectRaw('1')->from('operational_events')->whereColumn('operational_events.related_record_id', 'payment_attempts.id')
                        ->where('operational_events.related_record_type', PaymentAttempt::class)->where('operational_events.integration', OperationalEvent::IntegrationPayMongo)
                        ->whereIn('operational_events.status', [OperationalEvent::StatusReviewRequired, OperationalEvent::StatusFailed]);
                }))->pluck('id')->all();
        }

        return $this->accountingWorkIds[$kind];
    }

    /** @return array<string, int> */
    public function accountingWorkCounts(): array
    {
        abort_unless($this->isAccounting(), 403);

        return collect(['assessment', 'proof', 'provider'])->mapWithKeys(fn (string $kind): array => [$kind => count($this->accountingCaseIds($kind))])->all();
    }

    /** @return list<int> */
    private function currentCaseIds(string $kind): array
    {
        if ($this->currentWorkIds === null) {
            $this->currentWorkIds = ['prepare' => [], 'issues' => [], 'waiting' => [], 'finance' => [], 'ready' => []];
            $cases = Enrollment::query()->where('canonical_outcome', Enrollment::OutcomeInProgress)
                ->with(['credentialUser', 'admissionApplication', 'studentProfile', 'currentProposalVersion.confirmation',
                    'currentProposalVersion.curriculumVersion.program', 'currentProposalVersion.items.reservation'])
                ->get();

            foreach ($cases as $case) {
                $group = match (EnrollmentsTable::stage($case)) {
                    'Prepare subjects' => 'prepare',
                    'Waiting for learner' => 'waiting',
                    'Payment or fee review' => 'finance',
                    'Ready to finalize' => 'ready',
                    default => 'issues',
                };
                $this->currentWorkIds[$group][] = (int) $case->id;
            }
        }

        return $this->currentWorkIds[$kind];
    }

    /** @return array<string, int> */
    public function workCounts(): array
    {
        abort_unless($this->isRegistrar(), 403);

        return collect(['prepare', 'issues', 'waiting', 'finance', 'ready'])
            ->mapWithKeys(fn (string $key): array => [$key => count($this->currentCaseIds($key))])->all();
    }

    public function content(Schema $schema): Schema
    {
        if ($this->isAccounting() && $this->activeTab === 'overview') {
            return $schema->components([SchemaView::make('filament.admin.enrollments.accounting-workbench')
                ->viewData(fn (): array => ['counts' => $this->accountingWorkCounts()])]);
        }

        if ($this->isAccounting()) {
            $schema = parent::content($schema);

            return $schema->components([SchemaView::make('filament.admin.enrollments.queue-context'), ...$schema->getComponents()]);
        }

        if (! $this->isRegistrar()) {
            return parent::content($schema);
        }

        $batchResults = SchemaView::make('filament.pages.partials.enrollment-batch-results')
            ->viewData(fn (): array => ['results' => $this->finalizationResults])
            ->visible(fn (): bool => $this->finalizationResults !== []);

        if ($this->activeTab === 'ready_applicants') {
            return $schema->components([
                SchemaView::make('filament.admin.enrollments.ready-applicants')
                    ->viewData(fn (): array => $this->readyApplicantListData()),
            ]);
        }

        if ($this->activeTab === 'overview') {
            return $schema->components([
                $batchResults,
                SchemaView::make('filament.admin.enrollments.workbench')->viewData(fn (): array => [
                    'counts' => $this->workCounts(),
                    'applicantAction' => $this->getAction('readyApplicants', isMounting: false),
                    'continuingAction' => $this->getAction('startContinuingEnrollment', isMounting: false),
                    'lateContinuingAction' => $this->getAction('startLateContinuingEnrollment', isMounting: false),
                    'windowAction' => $this->getAction('enrollmentApplicability', isMounting: false),
                ]),
            ]);
        }

        $schema = parent::content($schema);

        return $schema->components([
            SchemaView::make('filament.admin.enrollments.queue-context'),
            $batchResults,
            ...$schema->getComponents(),
        ]);
    }

    public function getTitle(): string
    {
        if ($this->isHeadReader()) {
            return 'Enrollment oversight';
        }
        if ($this->isAccounting()) {
            return (string) ($this->getCachedTabs()[$this->activeTab ?? 'overview']?->getLabel() ?? 'Student accounts');
        }

        return $this->isRegistrar()
            ? (string) ($this->getCachedTabs()[$this->activeTab ?? 'overview']?->getLabel() ?? 'Enrollment')
            : parent::getTitle();
    }

    public function getSubheading(): ?string
    {
        if ($this->isHeadReader()) {
            return 'Read-only enrollment records. Registrar manages enrollment.';
        }
        if ($this->isAccounting()) {
            return match ($this->activeTab) {
                'overview' => 'Review assessments and payments, or find an account.',
                'assessment_required' => 'Review unassessed subjects, recorded changes and retained payment sources.',
                'payment_proof' => 'Check submitted proof against its named fees before recording a payment.',
                'payment_exceptions' => 'Resolve failed or unmatched online payment records.',
                default => 'Find a student and review the current term account.',
            };
        }
        if (! $this->isRegistrar()) {
            return parent::getSubheading();
        }

        return match ($this->activeTab) {
            'overview' => 'Prepare subjects, make enrollment official, or find a record.',
            'ready_applicants' => 'Applicants cleared by Admissions who have not started enrollment.',
            'ready_to_prepare' => 'Review drafts before sending subjects to the student.',
            'waiting_for_learner' => 'Students confirm the subjects issued to them. Open an enrollment if they need help.',
            'placement_shortages' => 'Review the current classes or requirements that need attention.',
            'finance_pending' => 'Subjects are confirmed. Payment or fee review is still needed.',
            'ready_to_finalize' => 'Review each cleared enrollment before making it official.',
            default => null,
        };
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return $this->isRegistrar() || $this->isAccounting()
            ? ($this->activeTab === 'overview' ? [] : [EnrollmentResource::getUrl('index') => ($this->isAccounting() ? 'Student accounts' : 'Enrollment'), $this->getTitle()])
            : parent::getBreadcrumbs();
    }

    /** @return list<string> */
    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), ...($this->isRegistrar() || $this->isAccounting() ? ['registrar-enrollment-workbench'] : [])];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return $this->isRegistrar() || $this->isAccounting() ? 'overview' : parent::getDefaultActiveTab();
    }

    public function getTabsContentComponent(): Component
    {
        return parent::getTabsContentComponent()->hidden($this->isRegistrar() || $this->isAccounting());
    }

    /** @return array<string, Tab> */
    public function getTabs(): array
    {
        if ($this->isRegistrar()) {
            return [
                'overview' => Tab::make('Enrollment'),
                'ready_applicants' => Tab::make('Ready applicants'),
                'ready_to_prepare' => Tab::make('Prepare subjects')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', $this->currentCaseIds('prepare'))),
                'waiting_for_learner' => Tab::make('Waiting for confirmation')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', $this->currentCaseIds('waiting'))),
                'placement_shortages' => Tab::make('Resolve enrollment issues')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', $this->currentCaseIds('issues'))),
                'finance_pending' => Tab::make('Waiting for payment or fee review')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', $this->currentCaseIds('finance'))),
                'ready_to_finalize' => Tab::make('Make enrollment official')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', $this->currentCaseIds('ready'))),
                'adjustments_drops' => Tab::make('Changes and drops history')->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                        $query->whereHas('proposalVersions', fn (Builder $query): Builder => $query->where('purpose', RegistrationProposalVersion::PurposeAdjustment))
                            ->orWhereHas('courseEnrollments', fn (Builder $query): Builder => $query->where('status', 'dropped'));
                    }),
                ),
                'official_history' => Tab::make('Official and closed enrollments')->modifyQueryUsing(fn (Builder $query): Builder => $query->where('canonical_outcome', '!=', Enrollment::OutcomeInProgress)),
                'all_cases' => Tab::make('Enrollment records'),
            ];
        }

        if (! $this->isAccounting()) {
            return [];
        }

        return [
            'overview' => Tab::make('Student accounts'),
            'accounts' => Tab::make('Account records')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->where(fn (Builder $scope): Builder => $scope->whereHas('termAccount')->orWhere('canonical_outcome', Enrollment::OutcomeInProgress)),
            ),
            'assessment_required' => Tab::make('Review assessments')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->whereIn('id', $this->accountingCaseIds('assessment')),
            ),
            'payment_proof' => Tab::make('Review payment proof')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', $this->accountingCaseIds('proof'))),
            'payment_exceptions' => Tab::make('Resolve online payments')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->whereIn('id', $this->accountingCaseIds('provider')),
            ),
        ];
    }

    /**
     * @return list<Action>
     */
    protected function getHeaderActions(): array
    {
        if ($this->isHeadReader()) {
            return [Action::make('returnToOversight')->label('Academic Oversight')->color('gray')
                ->url(AcademicApprovals::getUrl(['termId' => $this->oversightTermId]))];
        }
        if ($this->isAccounting()) {
            return [
                Action::make('backToAccounts')->label('Student accounts')->icon('heroicon-o-arrow-left')->color('gray')
                    ->url(EnrollmentResource::getUrl('index'))->visible(fn (): bool => $this->activeTab !== 'overview'),
                Action::make('exportAccountStatus')
                    ->label('Export account status')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->modalHeading('Export account status')
                    ->modalDescription('Review the current account position before downloading.')
                    ->modalIcon(null)
                    ->modalWidth(Width::FiveExtraLarge)
                    ->extraModalWindowAttributes(['autofocus' => true, 'tabindex' => '-1'])->stickyModalHeader()->modalFooterActionsAlignment(Alignment::End)->stickyModalFooter()
                    ->modalSubmitActionLabel(fn (): string => $this->financeExportFailed ? 'Try download again' : 'Download CSV')
                    ->modalCancelActionLabel('Close')
                    ->fillForm(function (): array {
                        $this->financeExportFailed = false;

                        return [];
                    })
                    ->modalSubmitAction(fn (Action $action): Action => $action->disabled(function (): bool {
                        $count = (clone $this->getFilteredSortedTableQuery())->whereHas('termAccount')->count();

                        return $count === 0 || $count > 10000;
                    }))
                    ->schema([
                        SchemaView::make('filament.admin.enrollments.finance-export-failure')
                            ->visible(fn (): bool => $this->financeExportFailed),
                        SchemaView::make('filament.admin.enrollments.account-status-export')
                            ->viewData(fn (): array => $this->accountStatusExportData()),
                    ])
                    ->visible(fn (): bool => $this->activeTab === 'accounts')
                    ->action(function (Action $action): void {
                        try {
                            $query = clone $this->getFilteredSortedTableQuery();
                            $excluded = (clone $query)->whereDoesntHave('termAccount')->count();
                            $enrollments = $query->whereHas('termAccount')->limit(10001)->get();
                            $export = app(CreateContextualFinanceExport::class)->createAccountStatus(
                                auth()->user(),
                                CreateContextualFinanceExport::AccountStatusPurpose,
                                $enrollments,
                                [
                                    'active_tab' => 'accounts',
                                    'filters' => $this->tableFilters ?? [],
                                    'search' => $this->getTableSearch(),
                                    'sort' => [
                                        'column' => $this->getTableSortColumn(),
                                        'direction' => $this->getTableSortDirection(),
                                    ],
                                    'preparation_cases_excluded' => $excluded,
                                ],
                            );
                            if ($export->outcome === FinanceExport::OutcomeNoRows) {
                                Notification::make()->title('No accounts to export')
                                    ->body('No matching cases have an account yet. Adjust the search or filters.')->info()->send();

                                return;
                            }
                            $this->redirect(route('finance.exports.download', $export));
                        } catch (ValidationException $exception) {
                            $this->financeExportFailed = true;
                            $action->halt();
                        } catch (Throwable $exception) {
                            report($exception);
                            $this->financeExportFailed = true;
                            $action->halt();
                        }
                    }),
            ];
        }

        return $this->isRegistrar() && $this->activeTab !== 'overview'
            ? [Action::make('backToEnrollment')->label('Back to enrollment')->icon('heroicon-o-arrow-left')->color('gray')->url(EnrollmentResource::getUrl('index'))]
            : [];
    }

    /** @return array<string, mixed> */
    public function accountStatusExportData(): array
    {
        abort_unless($this->isAccounting(), 403);
        $query = clone $this->getFilteredSortedTableQuery();
        $excluded = (clone $query)->whereDoesntHave('termAccount')->count();
        $accounts = (clone $query)->whereHas('termAccount');
        $count = (clone $accounts)->count();
        $preview = app(CreateContextualFinanceExport::class)->accountStatusPreview(
            auth()->user(), $accounts->limit(5)->get(),
        );
        $termId = data_get($this->tableFilters, 'term.value');
        $programId = data_get($this->tableFilters, 'program.value');

        return [
            'count' => $count,
            'excluded' => $excluded,
            'term' => filled($termId) ? (Term::find($termId)?->label ?? 'Selected term') : 'All terms',
            'program' => filled($programId) ? (Program::find($programId)?->name ?? 'Selected program') : 'All programs',
            'search' => $this->getTableSearch(),
            'purpose' => CreateContextualFinanceExport::AccountStatusPurpose,
            'headers' => $preview['headers'],
            'rows' => $preview['rows'],
        ];
    }

    public function enrollmentApplicabilityAction(): Action
    {
        return Action::make('enrollmentApplicability')->label('Who can enroll during this period')->link()->icon('heroicon-o-cog-6-tooth')->color('gray')
            ->visible(fn (): bool => $this->isRegistrar())
            ->authorize(fn (): bool => $this->isRegistrar())
            ->modalHeading('Who can enroll during this period')
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel('Save allowed learners')
            ->modalCancelActionLabel('Keep current setting')
            ->modalDescription('Choose who may start enrollment during the published dates. The dates stay the same.')
            ->schema([
                Select::make('term_id')->label('Enrollment term')->options(fn (): array => Term::query()->orderByDesc('starts_on')->pluck('label', 'id')->all())->searchable()->required()->live()
                    ->afterStateUpdated(fn (Set $set, mixed $state) => $set('applicability', filled($state) ? Term::query()->find($state)?->registration_applicability : null)),
                Radio::make('applicability')->label('Allowed learners')->options(RegistrationWindowApplicability::options())->required()
                    ->helperText('Your name, the time and the changes are recorded automatically.'),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                try {
                    app(RegistrationWindowApplicability::class)->record(Term::query()->findOrFail($data['term_id']), $actor, $data['applicability']);
                    Notification::make()->title('Allowed learners saved')->success()->send();
                } catch (Throwable $exception) {
                    Notification::make()->title('Allowed learners were not saved')->body($exception->getMessage())->danger()->persistent()->send();
                }
            });
    }

    public function readyApplicantsAction(): Action
    {
        return Action::make('readyApplicants')
            ->label('Ready applicant')
            ->icon('heroicon-o-user-plus')
            ->color('primary')
            ->authorize(fn (): bool => $this->isRegistrar())
            ->visible(fn (): bool => $this->isRegistrar())
            ->url(EnrollmentResource::getUrl('index', ['tab' => 'ready_applicants']));
    }

    public function startContinuingEnrollmentAction(): Action
    {
        return $this->continuingEnrollmentStartAction(false);
    }

    public function startLateContinuingEnrollmentAction(): Action
    {
        return $this->continuingEnrollmentStartAction(true);
    }

    private function continuingEnrollmentStartAction(bool $late): Action
    {
        return Action::make($late ? 'startLateContinuingEnrollment' : 'startContinuingEnrollment')
            ->label($late ? 'Approved late enrollment' : 'Continuing student')
            ->color('gray')
            ->icon('heroicon-o-plus-circle')
            ->tooltip($late ? 'Start with recorded late-enrollment approval' : 'Start continuing enrollment')
            ->modalHeading($late ? 'Start approved late enrollment' : 'Start continuing enrollment')
            ->modalDescription($late ? 'Use an existing school approval for this student and term. This does not make enrollment official.' : 'Choose a student and term. You’ll review their subjects next.')
            ->modalSubmitActionLabel($late ? 'Start approved enrollment' : 'Start enrollment')
            ->modalWidth(Width::Large)
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->authorize(fn (): bool => $this->isRegistrar())
            ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
            ->schema([
                Select::make('student_profile_id')
                    ->label('Student')
                    ->placeholder('Search name or student number')
                    ->relationship('studentProfile', 'student_number', modifyQueryUsing: fn (Builder $query): Builder => $query->with('program')->orderBy('student_number'))
                    ->getOptionLabelFromRecordUsing(fn (StudentProfile $record): string => collect([
                        $record->student_number,
                        trim($record->first_name.' '.$record->last_name),
                        $record->program?->code,
                    ])->filter()->implode(' · '))
                    ->searchable(['student_number', 'first_name', 'middle_name', 'last_name'])
                    ->required(),
                Select::make('term_id')
                    ->label('Enrollment term')
                    ->placeholder('Choose a term')
                    ->options(fn (): array => Term::query()
                        ->where('state', Term::StateActive)
                        ->orderByDesc('starts_on')
                        ->get()
                        ->mapWithKeys(fn (Term $term): array => [$term->id => $term->label])
                        ->all())
                    ->required(),
                ...($late ? [
                    TextInput::make('authority_reference')
                        ->label('Late enrollment approval reference')
                        ->helperText('Use the actual school approval. Your name and the time are recorded automatically.')
                        ->required()
                        ->maxLength(255),
                ] : []),
            ])
            ->action(function (array $data) use ($late): void {
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
                        $late ? 'LateAuthority' : 'RegistrarAssisted',
                        $late ? (string) ($data['authority_reference'] ?? '') : null,
                    );

                    Notification::make()
                        ->title($enrollment->wasRecentlyCreated ? 'Enrollment started' : 'Existing enrollment opened')
                        ->success()
                        ->send();

                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $enrollment]));
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title('Enrollment not started')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public function startReadyApplicantRegistrationAction(): Action
    {
        return Action::make('startReadyApplicantRegistration')
            ->label('Start enrollment')
            ->icon('heroicon-o-user-plus')
            ->labeledFrom('md')
            ->tooltip('Start enrollment for the selected applicant')
            ->modalHeading('Start enrollment')
            ->modalDescription('Review the applicant before continuing.')
            ->modalSubmitActionLabel('Start enrollment')
            ->modalCancelActionLabel('Back to applicants')
            ->authorize(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
            ->visible(fn (): bool => auth()->user()?->hasRole(User::StaffRoleRegistrar) ?? false)
            ->fillForm(function (array $arguments): array {
                $applicationId = $arguments['application_id'] ?? null;
                $termId = $arguments['term_id'] ?? null;
                $this->readyApplicantStartId = filled($applicationId) ? (int) $applicationId : null;

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
                    ->hidden(fn (): bool => $this->readyApplicantStartId !== null)
                    ->dehydratedWhenHidden()
                    ->options(fn (): array => $this->startableReadyApplicants()
                        ->mapWithKeys(fn (AdmissionApplication $application): array => [
                            $application->id => collect([
                                $application->application_reference,
                                $application->last_name.', '.$application->first_name,
                                $application->program?->code,
                                $application->term?->label,
                            ])->filter()->implode(' · '),
                        ])->all())
                    ->searchable()
                    ->live()
                    ->helperText('Choose an applicant cleared by Admissions. Existing enrollments are in Enrollment records.')
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
                Hidden::make('term_id')->required(),
                SchemaView::make('filament.admin.enrollments.ready-applicant-start-context')
                    ->viewData(fn (Get $get): array => [
                        'application' => AdmissionApplication::query()->with(['program', 'term'])
                            ->find($this->readyApplicantStartId ?? $get('application_id')),
                    ]),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                if (! $actor instanceof User || ! $actor->hasRole(User::StaffRoleRegistrar)) {
                    abort(403, 'Unauthorized.');
                }

                if ($this->readyApplicantStartId !== null && (int) $data['application_id'] !== $this->readyApplicantStartId) {
                    Notification::make()->title('Registration not started')
                        ->body('The selected applicant changed. Return to Ready applicants and open the correct person.')
                        ->danger()->send();

                    return;
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
                        null,
                    );

                    Notification::make()
                        ->title('Enrollment started')
                        ->body('Review the proposed subjects and any items that need attention.')
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
            });
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

    public function updatedReadyApplicantSearch(): void
    {
        $this->resetPage('readyApplicantsPage');
    }

    /** @return array{applications: LengthAwarePaginator} */
    public function readyApplicantListData(): array
    {
        abort_unless($this->isRegistrar(), 403);

        $search = mb_strtolower(trim($this->readyApplicantSearch));
        $applications = $this->startableReadyApplicants()
            ->filter(fn (AdmissionApplication $application): bool => $search === '' || str_contains(mb_strtolower(collect([
                $application->first_name, $application->middle_name, $application->last_name,
                $application->application_reference, $application->program?->name, $application->term?->label,
            ])->filter()->implode(' ')), $search))
            ->sortBy([['last_name', 'asc'], ['first_name', 'asc'], ['id', 'asc']])
            ->values();
        $page = max(1, min((int) $this->getPage('readyApplicantsPage'), max(1, (int) ceil($applications->count() / 10))));

        return ['applications' => new LengthAwarePaginator(
            $applications->forPage($page, 10)->values(),
            $applications->count(),
            10,
            $page,
            ['path' => EnrollmentResource::getUrl('index'), 'pageName' => 'readyApplicantsPage'],
        )];
    }

    /** @return Collection<int, AdmissionApplication> */
    private function startableReadyApplicants(): Collection
    {
        $applications = $this->readyApplicants();
        $existingCases = Enrollment::query()
            ->whereIn('term_id', $applications->pluck('term_id')->filter()->all())
            ->where(function (Builder $query) use ($applications): void {
                $query->whereIn('admission_application_id', $applications->pluck('id')->all())
                    ->orWhereIn('credential_user_id', $applications->pluck('user_id')->filter()->all());
            })
            ->get();

        return $applications->reject(fn (AdmissionApplication $application): bool => $existingCases->contains(
            fn (Enrollment $case): bool => (int) $case->term_id === (int) $application->term_id
                && ((int) $case->admission_application_id === (int) $application->id
                    || ($application->user_id && (int) $case->credential_user_id === (int) $application->user_id)),
        ));
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
