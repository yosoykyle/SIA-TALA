<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Enrollment\RegistrationWindowApplicability;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\IndividualAssessmentSources;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Filament\Pages\AcademicApprovals;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Filament\Resources\Enrollments\Tables\StudentAccountsTable;
use App\Models\AdmissionApplication;
use App\Models\Assessment;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FinanceExport;
use App\Models\OfficialOutputRestriction;
use App\Models\PaymentEvidenceVersion;
use App\Models\Program;
use App\Models\RegistrationProposalVersion;
use App\Models\StudentProfile;
use App\Models\Term;
use App\Models\TermAccount;
use App\Models\TranscriptRequest;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use App\Support\SafeUserNotification;
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
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Throwable;

class ListEnrollments extends ListRecords
{
    public const ACCOUNTING_TAB_KEYS = [
        'overview',
        'accounts',
        'assessment_required',
        'payment_proof',
        'student_changes',
    ];

    public const REGISTRAR_TAB_KEYS = [
        'overview',
        'ready_applicants',
        'ready_to_prepare',
        'waiting_for_learner',
        'placement_shortages',
        'finance_pending',
        'ready_to_finalize',
        'adjustments_drops',
        'official_history',
        'all_cases',
    ];

    protected static string $resource = EnrollmentResource::class;

    #[Url(as: 'account')]
    public ?int $selectedAccountingId = null;

    #[Url(as: 'view')]
    public string $accountingSection = 'work';

    #[Url(as: 'scope')]
    public string $accountingScope = '';

    private function normalizeAccountingTab(): void
    {
        if (! $this->isAccounting() || ! filled($this->activeTab)) {
            return;
        }

        if ($this->activeTab === 'assessment_reviews') {
            $this->activeTab = 'assessment_required';

            return;
        }

        if (! in_array($this->activeTab, self::ACCOUNTING_TAB_KEYS, true)) {
            $this->activeTab = 'overview';
        }
    }

    private function totalTermsCount(): int
    {
        return $this->memoTotalTerms ??= Term::query()->count();
    }

    /** @return list<int> */
    public function accountingPendingLifecycleIds(): array
    {
        return $this->memoPendingLifecycleIds ??= app(LifecycleAccountingReview::class)->pendingEnrollmentIds();
    }

    public function accountingTermGateActive(): bool
    {
        $this->normalizeAccountingTab();

        if (! $this->isAccounting() || $this->activeTab !== 'accounts') {
            return false;
        }

        if ($this->totalTermsCount() <= 1) {
            return false;
        }

        if ($this->accountingScope === 'all' || $this->selectedAccountingId !== null || $this->hasTableSearch()) {
            return false;
        }

        if (collect(Arr::dot($this->tableFilters ?? []))->contains(fn (mixed $value): bool => filled($value))) {
            return false;
        }

        if (filled(request()->query('proof'))) {
            return false;
        }

        return true;
    }

    public function selectAccountingTerm(int $termId): void
    {
        abort_unless($this->isAccounting(), 403);
        $this->accountingScope = '';
        $filters = $this->tableFilters ?? [];
        data_set($filters, 'term.value', (string) $termId);
        $this->getTableFiltersForm()->fill($filters);
        $this->handleTableFilterUpdates();
        $this->selectedAccountingId = null;
        $this->accountingSection = 'work';
        $this->flushCachedTableRecords();
    }

    public function showAllAccountingTerms(): void
    {
        abort_unless($this->isAccounting(), 403);
        $this->removeTableFilter('term');
        $this->accountingScope = 'all';
        $this->selectedAccountingId = null;
        $this->accountingSection = 'work';
        $this->flushCachedTableRecords();
    }

    public function resetAccountingScope(): void
    {
        abort_unless($this->isAccounting(), 403);
        $this->accountingScope = '';
        $this->resetTableFiltersForm();
        $this->resetTableSearch();
        $this->selectedAccountingId = null;
        $this->accountingSection = 'work';
        $this->resetPage($this->getTablePaginationPageName());
        $this->flushCachedTableRecords();
    }

    public function clearAccountingTermFilter(): void
    {
        abort_unless($this->isAccounting(), 403);
        $this->removeTableFilter('term');
        $this->accountingScope = $this->activeTab === 'accounts' ? 'all' : '';
        $this->selectedAccountingId = null;
        $this->accountingSection = 'work';
        $this->flushCachedTableRecords();
    }

    public function selectedAccountingRecord(): ?Enrollment
    {
        abort_unless($this->isAccounting(), 403);
        $records = $this->getTableRecords();
        $record = $this->selectedAccountingId === null ? $records->first() : $records->firstWhere('id', $this->selectedAccountingId);
        if ($record !== null) {
            Gate::authorize('view', $record);
        }

        return $record;
    }

    public function selectAccountingRecord(int $recordId): void
    {
        abort_unless($this->isAccounting(), 403);
        abort_unless($this->getTableRecords()->contains('id', $recordId), 404);
        $this->selectedAccountingId = $recordId;
        $this->accountingSection = 'work';
        $this->dispatch('record-selected');
    }

    public function showAccountingSection(string $section): void
    {
        abort_unless($this->isAccounting() && in_array($section, ['work', 'history'], true), 404);
        $case = $this->selectedAccountingRecord();
        $hasLifecycleHistory = $case !== null && app(LifecycleAccountingReview::class)->safeStatus($case)['reviewed'] > 0;
        abort_unless($case !== null && ($section !== 'history' || $case->termAccount !== null || $hasLifecycleHistory), 404);
        $this->selectedAccountingId = $case->id;
        $this->accountingSection = $section;
        $this->dispatch('record-selected');
    }

    private bool $hadAccountingTermFilter = false;

    public function clearAccountingFilters(): void
    {
        abort_unless($this->isAccounting(), 403);
        $hadTermFilter = $this->activeTab === 'accounts' && filled(data_get($this->tableFilters, 'term.value'));
        $this->resetTableFiltersForm();
        $this->resetTableSearch();
        $this->selectedAccountingId = null;
        $this->accountingSection = 'work';
        if ($hadTermFilter) {
            $this->accountingScope = 'all';
        }
        $this->flushCachedTableRecords();
    }

    public function updating(string $property): void
    {
        if ($this->isAccounting() && $this->activeTab === 'accounts' && ($property === 'tableFilters' || str_starts_with($property, 'tableFilters.term'))) {
            $this->hadAccountingTermFilter = filled(data_get($this->tableFilters, 'term.value'));
        }
    }

    public function updated(string $property): void
    {
        $this->normalizeAccountingTab();

        if ($this->isAccounting() && (in_array($property, ['activeTab', 'tableSearch', 'tableRecordsPerPage'], true) || str_starts_with($property, 'tableFilters'))) {
            $this->selectedAccountingId = null;
            $this->accountingSection = 'work';
            if ($property === 'activeTab') {
                $this->accountingScope = '';
            } elseif ($property === 'tableFilters' || str_starts_with($property, 'tableFilters.term')) {
                if (filled(data_get($this->tableFilters, 'term.value'))) {
                    $this->accountingScope = '';
                } elseif ($this->activeTab === 'accounts' && $this->hadAccountingTermFilter) {
                    $this->accountingScope = 'all';
                }
            }
            $this->hadAccountingTermFilter = false;
        }
    }

    public function updatedPaginators(int $page, string $pageName): void
    {
        if ($this->isAccounting() && ! $this->shouldMountInteractsWithTable && $pageName === $this->getTablePaginationPageName()) {
            $this->selectedAccountingId = null;
            $this->accountingSection = 'work';
        }
    }

    #[On('accounting-record-updated')]
    public function refreshAccountingRecords(): void
    {
        abort_unless($this->isAccounting(), 403);
        $this->accountingWorkIds = null;
        $this->memoPendingLifecycleIds = null;
        $this->memoPendingLifecycleCounts = [];
        $this->flushCachedTableRecords();
    }

    public function bootedInteractsWithTable(): void
    {
        $this->normalizeAccountingTab();
        parent::bootedInteractsWithTable();
        if (! $this->isAccounting() || ! $this->shouldMountInteractsWithTable || $this->selectedAccountingId === null || request()->query('page') !== null) {
            return;
        }
        $position = $this->getFilteredSortedTableQuery()->pluck('enrollments.id')->search($this->selectedAccountingId, true);
        if ($position !== false) {
            $this->setPage(intdiv($position, max(1, (int) $this->getTableRecordsPerPage())) + 1, $this->getTablePaginationPageName());
        }
    }

    /** @return array{label:string,icon:string} */
    public function accountingRecordSummary(Enrollment $case): array
    {
        $matchingQueues = collect(['student_changes', 'proof', 'assessment'])
            ->filter(fn (string $kind): bool => in_array($case->id, $this->accountingCaseIds($kind), true))
            ->values();
        $extraSuffix = $matchingQueues->count() > 1
            ? ' (+'.($matchingQueues->count() - 1).' more)'
            : '';

        if ($matchingQueues->contains('student_changes')) {
            $pending = $this->memoPendingLifecycleCounts[$case->id] ??= app(LifecycleAccountingReview::class)->safeStatus($case)['pending'];
            $label = $pending.' pending '.Str::plural('change', $pending);
            if ($case->termAccount === null) {
                $label .= ' · account source unavailable';
            }

            return ['label' => $label.$extraSuffix, 'icon' => 'heroicon-o-clipboard-document-check'];
        }
        foreach ([
            'proof' => ['Receipts to review', 'heroicon-o-document-magnifying-glass'],
            'assessment' => ['Assessment review', 'heroicon-o-document-currency-dollar'],
        ] as $kind => [$label, $icon]) {
            if ($matchingQueues->contains($kind)) {
                return ['label' => $label.$extraSuffix, 'icon' => $icon];
            }
        }
        if ($case->termAccount === null) {
            return $case->canonical_outcome === Enrollment::OutcomeInProgress
                ? ['label' => 'Waiting for Registrar', 'icon' => 'heroicon-o-clock']
                : ['label' => 'Account source unavailable', 'icon' => 'heroicon-o-exclamation-triangle'];
        }

        return ['label' => StudentAccountsTable::rowData($case, $this->activeTab)['status'], 'icon' => 'heroicon-o-banknotes'];
    }

    public function accountingInspectorView(): string
    {
        if (in_array($this->accountingSection, ['account', 'changes', 'proof', 'history', 'assessment'], true)) {
            return $this->accountingSection;
        }

        return match ($this->activeTab) {
            'student_changes' => 'changes', 'payment_proof' => 'proof',
            'assessment_required' => 'assessment', default => 'overview',
        };
    }

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

    private ?int $memoTotalTerms = null;

    /** @var Collection<int, Term>|null */
    private ?Collection $memoActiveTerms = null;

    /** @var list<int>|null */
    private ?array $memoPendingLifecycleIds = null;

    /** @var array<int, int> */
    private array $memoPendingLifecycleCounts = [];

    /** @return list<int> */
    private function accountingCaseIds(string $kind): array
    {
        if ($this->accountingWorkIds === null) {
            $this->accountingWorkIds = ['assessment' => [], 'proof' => [],
                'student_changes' => $this->accountingPendingLifecycleIds()];
            $cases = Enrollment::query()->with([
                'term',
                'currentProposalVersion.confirmation',
                'currentCorVersion',
                'termAccount.enrollment',
                'termAccount.assessments.obligations.paymentAllocations.payment',
                'termAccount.assessments.obligations.coverages',
                'termAccount.paymentEvidenceVersions',
            ])->where(fn (Builder $query): Builder => $query->whereHas('termAccount')->orWhere('canonical_outcome', Enrollment::OutcomeInProgress))->get();
            foreach ($cases as $case) {
                $assessment = $case->termAccount?->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();
                $needsInProgressAssessment = $case->canonical_outcome === Enrollment::OutcomeInProgress
                    && ($assessment === null || (int) $assessment->source_proposal_version_id !== (int) $case->current_proposal_version_id);

                if ($needsInProgressAssessment) {
                    $this->accountingWorkIds['assessment'][] = (int) $case->id;
                } else {
                    $sources = app(IndividualAssessmentSources::class)->forEnrollment($case, useLoadedRelations: true);
                    if ($sources['course_drop'] !== null
                        || ($sources['adjustment'] && (int) $assessment?->source_proposal_version_id !== (int) $case->current_proposal_version_id)) {
                        $this->accountingWorkIds['assessment'][] = (int) $case->id;
                    } elseif ($case->termAccount !== null && $assessment !== null && (int) $assessment->version > 1) {
                        $position = app(TermAccountProjection::class)->forAccount($case->termAccount, useLoadedRelations: true);
                        if ($position['reconciliation_required'] ?? false) {
                            $this->accountingWorkIds['assessment'][] = (int) $case->id;
                        }
                    }
                }
                if ($case->termAccount?->paymentEvidenceVersions->contains('state', PaymentEvidenceVersion::StateSubmitted)) {
                    $this->accountingWorkIds['proof'][] = (int) $case->id;
                }
            }
        }

        return $this->accountingWorkIds[$kind];
    }

    /** @return array<string, int> */
    public function accountingWorkCounts(): array
    {
        abort_unless($this->isAccounting(), 403);

        return collect(['assessment', 'proof', 'student_changes'])->mapWithKeys(fn (string $kind): array => [$kind => count($this->accountingCaseIds($kind))])->all();
    }

    public function accountingUniqueReviewCount(): int
    {
        abort_unless($this->isAccounting(), 403);

        return collect(['assessment', 'proof', 'student_changes'])
            ->flatMap(fn (string $kind): array => $this->accountingCaseIds($kind))
            ->unique()
            ->count();
    }

    /** @return Collection<int, Term> */
    private function accountingActiveTerms(): Collection
    {
        return $this->memoActiveTerms ??= Term::query()
            ->where('state', Term::StateActive)
            ->latest('starts_on')
            ->latest('id')
            ->get();
    }

    public function accountingActiveTerm(): ?Term
    {
        abort_unless($this->isAccounting(), 403);

        return $this->accountingActiveTerms()->first();
    }

    public function accountingOtherTermPendingCount(?int $scopedTermId = null): int
    {
        abort_unless($this->isAccounting(), 403);

        $pendingIds = collect(['assessment', 'proof', 'student_changes'])
            ->flatMap(fn (string $kind): array => $this->accountingCaseIds($kind))
            ->unique()
            ->values()
            ->all();

        if ($pendingIds === []) {
            return 0;
        }

        return Enrollment::query()
            ->whereIn('id', $pendingIds)
            ->when($scopedTermId !== null, fn (Builder $query): Builder => $query->where('term_id', '!=', $scopedTermId))
            ->count();
    }

    public function accountingHiddenCurrentQueueCount(int $scopedTermId): int
    {
        abort_unless($this->isAccounting(), 403);

        $kind = match ($this->activeTab) {
            'assessment_required' => 'assessment',
            'payment_proof' => 'proof',
            'student_changes' => 'student_changes',
            default => null,
        };

        if ($kind === null) {
            return 0;
        }

        $ids = $this->accountingCaseIds($kind);
        if ($ids === []) {
            return 0;
        }

        return Enrollment::query()
            ->whereIn('id', $ids)
            ->where('term_id', '!=', $scopedTermId)
            ->count();
    }

    /**
     * @return array{
     *     activeTermId: ?int,
     *     activeTermLabel: string,
     *     activeTermNames: list<string>,
     *     totalTerms: int,
     *     feePlanPublishedCount: int,
     *     feePlanAllPublishedCount: int,
     *     feePlanScopeLabel: string,
     *     feePlanDraftCount: int,
     *     openAccountsCount: int,
     *     unpaidAccountsCount: int,
     *     torOpenCount: int,
     *     torRestrictedCount: int,
     *     uniqueReviewCount: int,
     *     otherTermPendingCount: int,
     *     multiTermPendingNote: ?string,
     * }
     */
    public function accountingOverviewSummary(): array
    {
        abort_unless($this->isAccounting(), 403);

        $activeTerms = $this->accountingActiveTerms();
        $activeTerm = $activeTerms->first();
        $activeTermNames = $activeTerms->pluck('label')->filter()->values()->all();
        $otherTermPendingCount = $activeTerm !== null
            ? $this->accountingOtherTermPendingCount($activeTerm->id)
            : $this->accountingOtherTermPendingCount();
        $openAccountsCount = TermAccount::query()->where('state', TermAccount::StateOpen)->count();
        $feePlanStats = FeePlan::query()
            ->selectRaw(
                'SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as all_published_count, SUM(CASE WHEN state = ? THEN 1 ELSE 0 END) as draft_count'
                .($activeTerm !== null ? ', SUM(CASE WHEN state = ? AND term_id = ? THEN 1 ELSE 0 END) as active_published_count' : ''),
                $activeTerm !== null
                    ? [FeePlan::StatePublished, FeePlan::StateDraft, FeePlan::StatePublished, $activeTerm->id]
                    : [FeePlan::StatePublished, FeePlan::StateDraft],
            )
            ->first();
        $allPublishedCount = (int) ($feePlanStats?->all_published_count ?? 0);
        $scopedPublishedCount = $activeTerm !== null
            ? (int) ($feePlanStats?->active_published_count ?? 0)
            : $allPublishedCount;

        return [
            'activeTermId' => $activeTerm?->id,
            'activeTermLabel' => $activeTerm?->label ?? 'No active term',
            'activeTermNames' => $activeTermNames,
            'totalTerms' => $this->totalTermsCount(),
            'feePlanPublishedCount' => $scopedPublishedCount,
            'feePlanAllPublishedCount' => $allPublishedCount,
            'feePlanScopeLabel' => $activeTerm?->label ?? 'All terms',
            'feePlanDraftCount' => (int) ($feePlanStats?->draft_count ?? 0),
            'openAccountsCount' => $openAccountsCount,
            'unpaidAccountsCount' => $openAccountsCount,
            'torOpenCount' => TranscriptRequest::query()->where('state', TranscriptRequest::StateOpen)->count(),
            'torRestrictedCount' => OfficialOutputRestriction::query()
                ->where('output_type', OfficialOutputRestriction::OutputTor)
                ->whereNull('resolved_at')
                ->count(),
            'uniqueReviewCount' => $this->accountingUniqueReviewCount(),
            'otherTermPendingCount' => $otherTermPendingCount,
            'multiTermPendingNote' => $activeTerm !== null && $otherTermPendingCount > 0
                ? $otherTermPendingCount.' pending '.Str::plural('task', $otherTermPendingCount).' from other terms '.($otherTermPendingCount === 1 ? 'is' : 'are').' included in the queues below.'
                : null,
        ];
    }

    /**
     * @return array{
     *     multiTerm: bool,
     *     activeTerm: ?Term,
     *     selectedTerm: ?Term,
     *     selectedProgram: ?Program,
     *     otherTermPendingCount: int,
     *     hiddenCurrentQueueCount: int,
     *     workCounts: array<string, int>,
     *     hasTermOrProgramFilter: bool,
     * }
     */
    public function accountingScopeContext(): array
    {
        abort_unless($this->isAccounting(), 403);

        $termId = (int) data_get($this->tableFilters, 'term.value') ?: null;
        $programId = (int) data_get($this->tableFilters, 'program.value') ?: null;

        return [
            'multiTerm' => $this->totalTermsCount() > 1,
            'activeTerm' => $this->accountingActiveTerm(),
            'selectedTerm' => $termId !== null ? Term::query()->with('academicYear')->find($termId) : null,
            'selectedProgram' => $programId !== null ? Program::query()->find($programId) : null,
            'otherTermPendingCount' => $termId !== null ? $this->accountingOtherTermPendingCount($termId) : 0,
            'hiddenCurrentQueueCount' => $termId !== null ? $this->accountingHiddenCurrentQueueCount($termId) : 0,
            'workCounts' => $this->accountingWorkCounts(),
            'hasTermOrProgramFilter' => $termId !== null || $programId !== null,
        ];
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
        $this->normalizeAccountingTab();

        if ($this->isAccounting() && $this->activeTab === 'overview') {
            return $schema->components([SchemaView::make('filament.admin.enrollments.accounting-workbench')
                ->viewData(fn (): array => [
                    'counts' => $this->accountingWorkCounts(),
                    'overviewSummary' => $this->accountingOverviewSummary(),
                ])]);
        }

        if ($this->isAccounting()) {
            return $schema->components([SchemaView::make('filament.admin.enrollments.accounting-workspace')]);
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
            $this->normalizeAccountingTab();

            return $this->activeTab === 'overview' ? 'Accounting overview' : 'Student accounts';
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
            $this->normalizeAccountingTab();

            return match ($this->activeTab) {
                'overview' => 'Review pending work or find a student’s account.',
                'accounts' => $this->accountingTermGateActive()
                    ? 'Select a term or search for a student before loading full account records.'
                    : 'Search and filter student account records by term or program.',
                'payment_proof' => 'Verify submitted payment receipts and record verification results across all terms.',
                'assessment_required' => 'Review unassessed enrollments, adjusted subjects and earlier payments across all terms.',
                'student_changes' => 'Review recorded student status changes and confirm account updates across all terms.',
                default => 'Find a student and review their account work.',
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
        if ($this->isAccounting()) {
            return $this->activeTab === 'overview' ? [] : [
                EnrollmentResource::getUrl('index') => 'Overview',
                $this->getTitle(),
            ];
        }

        return $this->isRegistrar()
            ? ($this->activeTab === 'overview' ? [] : [EnrollmentResource::getUrl('index') => 'Enrollment', $this->getTitle()])
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
            'overview' => Tab::make('Accounting overview'),
            'accounts' => Tab::make('All student accounts'),
            'assessment_required' => Tab::make('Assessment reviews')->modifyQueryUsing(
                fn (Builder $query): Builder => $query->whereIn('id', $this->accountingCaseIds('assessment')),
            ),
            'payment_proof' => Tab::make('Review receipts')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', $this->accountingCaseIds('proof'))),
            'student_changes' => Tab::make('Student status changes')->modifyQueryUsing(fn (Builder $query): Builder => $query->whereIn('id', $this->accountingCaseIds('student_changes'))),
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
                Action::make('accountingAllTerms')
                    ->label('All terms')
                    ->color('gray')
                    ->visible(fn (): bool => $this->activeTab === 'accounts'
                        && ! $this->accountingTermGateActive()
                        && filled(data_get($this->tableFilters, 'term.value')))
                    ->action(fn () => $this->showAllAccountingTerms()),
                Action::make('accountingChangeTerm')
                    ->label('Change term')
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->color('gray')
                    ->visible(fn (): bool => $this->activeTab === 'accounts'
                        && ! $this->accountingTermGateActive()
                        && $this->totalTermsCount() > 1)
                    ->action(fn () => $this->resetAccountingScope()),
                Action::make('exportAccountStatus')
                    ->label('Export account status')->color('gray')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->disabled(fn (): bool => $this->activeTab !== 'accounts'
                        || $this->accountingTermGateActive()
                        || ! (clone $this->getFilteredSortedTableQuery())->whereHas('termAccount')->exists())
                    ->tooltip(fn (): ?string => ($this->activeTab === 'accounts'
                        && ! $this->accountingTermGateActive()
                        && ! (clone $this->getFilteredSortedTableQuery())->whereHas('termAccount')->exists())
                        ? 'No term accounts match the current view.'
                        : null)
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
                        if ($this->activeTab !== 'accounts' || $this->accountingTermGateActive()) {
                            return true;
                        }

                        $count = (clone $this->getFilteredSortedTableQuery())->whereHas('termAccount')->count();

                        return $count === 0 || $count > 10000;
                    }))
                    ->schema([
                        SchemaView::make('filament.admin.enrollments.finance-export-failure')
                            ->visible(fn (): bool => $this->financeExportFailed),
                        SchemaView::make('filament.admin.enrollments.account-status-export')
                            ->viewData(fn (): array => $this->accountStatusExportData()),
                    ])
                    ->visible(fn (): bool => $this->activeTab === 'accounts' && ! $this->accountingTermGateActive())
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

        return [];
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
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Save allowed students')
            ->modalCancelActionLabel('Keep current setting')
            ->modalDescription('Choose who may start enrollment during the published dates. The dates stay the same.')
            ->schema([
                Select::make('term_id')->label('Enrollment term')->options(fn (): array => Term::query()->orderByDesc('starts_on')->pluck('label', 'id')->all())->searchable()->required()->live()
                    ->afterStateUpdated(fn (Set $set, mixed $state) => $set('applicability', filled($state) ? Term::query()->find($state)?->registration_applicability : null)),
                Radio::make('applicability')->label('Allowed students')->options(RegistrationWindowApplicability::options())->required()
                    ->helperText('Your name, the time and the changes are recorded automatically.'),
            ])
            ->action(function (array $data): void {
                $actor = auth()->user();
                abort_unless($actor instanceof User && $actor->hasRole(User::StaffRoleRegistrar), 403);
                try {
                    app(RegistrationWindowApplicability::class)->record(Term::query()->findOrFail($data['term_id']), $actor, $data['applicability']);
                    Notification::make()->title('Allowed students saved')->success()->send();
                } catch (Throwable $exception) {
                    SafeUserNotification::sendError(
                        'Allowed students were not saved',
                        $exception,
                        'Allowed students could not be updated. Keep the current setting and try again.',
                        persistent: true,
                    );
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
            ->stickyModalHeader()
            ->stickyModalFooter()
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
                        ->label('Approval memo or reference')
                        ->placeholder('e.g., Memo 2026-014 or approving office')
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
                    SafeUserNotification::sendError(
                        'Enrollment not started',
                        $exception,
                        'Enrollment could not be started right now. Refresh the page and try again.',
                    );
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
                } catch (ValidationException|AuthorizationException|Throwable $exception) {
                    SafeUserNotification::sendError(
                        'Registration not started',
                        $exception,
                        'An unexpected error occurred while starting registration. Please try again or contact system support.',
                    );
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
