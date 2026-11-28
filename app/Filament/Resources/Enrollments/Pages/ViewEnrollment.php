<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Academics\IrregularStudentReasons;
use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Enrollment\AdvisedSubjectSuggestion;
use App\Actions\Enrollment\ApplyRegistrationAdjustment;
use App\Actions\Enrollment\CancelRegistrationCase;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\FinalizeOfficialEnrollment;
use App\Actions\Enrollment\IssueRegistrationProposal;
use App\Actions\Enrollment\PlaceRegistrationProposal;
use App\Actions\Enrollment\PrepareRegistrationProposal;
use App\Actions\Enrollment\RecordCourseDrop;
use App\Actions\Enrollment\RecordGraduatingOverloadAuthority;
use App\Actions\Enrollment\RecordLateEnrollmentReopenAuthority;
use App\Actions\Enrollment\RecordRegistrationAdjustmentFinanceConfirmation;
use App\Actions\Enrollment\RecordRegistrationCreditEvaluation;
use App\Actions\Enrollment\RecordRegistrationLateAuthority;
use App\Actions\Enrollment\RecordRegistrationSourceImpactReview;
use App\Actions\Enrollment\RegistrationAcademicEligibilityQuery;
use App\Actions\Enrollment\RegistrationClassChoices;
use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\ReopenRegistrationCase;
use App\Actions\Enrollment\RetryStandardRegistrationPreparation;
use App\Actions\Enrollment\ReturnIrregularEnrollmentRequest;
use App\Actions\Enrollment\StudentUnitLoadService;
use App\Actions\Finance\AccountingDisplay;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\IndividualAssessmentSources;
use App\Actions\Finance\MapOfficialReceiptToPayment;
use App\Actions\Finance\PaymentConfirmationService;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Actions\Finance\RevalidateAssessmentEffects;
use App\Actions\Finance\ReverseApprovedCoverage;
use App\Actions\Finance\ReversePaymentPosting;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\SchoolPaymentChannels;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\Scheduling\ResolveTimetableRevisionRegistrationImpact;
use App\Actions\StudentLifecycle\LifecycleAccountingReview;
use App\Actions\StudentLifecycle\RecordLifecycleAccountingReview;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Schemas\TransferCreditingForm;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Filament\Resources\FeePlans\Schemas\FeePlanForm;
use App\Models\AdmissionApplication;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FinanceExport;
use App\Models\IrregularEnrollmentRequest;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentEvidenceVersion;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationLateAuthority;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\StudentProfile;
use App\Models\TermOffering;
use App\Models\TimetableRevision;
use App\Models\TranscriptRequest;
use App\Models\User;
use App\Support\DecimalMoney;
use App\Support\RegistrationSchedulePresentation;
use App\Support\SafeUserNotification;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section as SchemaSection;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\RawJs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use RuntimeException;
use Throwable;

class ViewEnrollment extends ViewRecord
{
    use WithPagination;

    protected static string $resource = EnrollmentResource::class;

    /** @var array<string, array{reason: string, resolved: bool}|null> */
    private array $regularPreparationBlockers = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $advisedSuggestions = [];

    #[Url(as: 'view')]
    public string $enrollmentView = 'overview';

    #[Url(as: 'queue')]
    public ?string $returnQueue = null;

    /** @var array{event_id:int,source_fingerprint:string,current_result_id:?int,account_id:int}|array{} */
    #[Locked]
    public array $reviewedLifecycleChange = [];

    /** @var array<string, string> */
    #[Locked]
    public array $reviewedLifecycleFinancialSources = [];

    /** @var array<string, string> */
    #[Locked]
    public array $lifecycleFinancialSourceOptions = [];

    #[Url(as: 'proof')]
    public ?int $paymentProofId = null;

    #[Locked]
    public bool $financeExportFailed = false;

    /** @var array<int, string> Reviewed coverage ID to source fingerprint, fixed when the reversal form opens. */
    #[Locked]
    public array $reviewedCoverage = [];

    /** @var array<int, string> Reviewed payment ID to source fingerprint, fixed when the refund form opens. */
    #[Locked]
    public array $reviewedPayments = [];

    /** @var array<string, mixed> Reviewed assessment and retained sources, fixed when the reconciliation form opens. */
    #[Locked]
    public array $reviewedReconciliation = [];

    /** @var array<string, mixed> Exact confirmed registration change reviewed when Accounting opens the form. */
    #[Locked]
    public array $reviewedNoCostChange = [];

    /** @var array<string, mixed> Exact registration change fixed when its late-approval form opens. */
    #[Locked]
    public array $reviewedLateChange = [];

    /** @var array<string, mixed> Exact eligible change/drop approvals reviewed by the current action. */
    #[Locked]
    public array $reviewedRegistrationLateAuthorities = [];

    /** @var array<string, mixed> Exact unused reopening authorities reviewed by the current action. */
    #[Locked]
    public array $reviewedLateReopenAuthorities = [];

    /** @var int|null Exact source bound after recording approval in this mounted flow. */
    #[Locked]
    public ?int $continuedLateReopenAuthorityId = null;

    #[Locked]
    public ?int $reviewedProposalVersionId = null;

    /** @var array{submission:int,lock:int,evaluation:?int}|null */
    #[Locked]
    public ?array $reviewedAcademicEvaluation = null;

    /** @var array{change_type:string,current_course_enrollment_id:?int,cor_id:int,lock_version:int,pending_adjustment_id:?int,revise_proposal_id:?int}|null */
    #[Locked]
    public ?array $reviewedAdjustmentRequest = null;

    #[Url]
    public ?int $oversightTermId = null;

    /** @var array<string, mixed> */
    #[Url]
    public array $returnFilters = [];

    #[Url]
    public ?string $returnSearch = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        if ($this->isAccountingActor() && ! $this->isAccountingWorkspace()
            && request()->routeIs('filament.admin.resources.enrollments.view')) {
            $this->redirect($this->enrollmentUrl());

            return;
        }
        $this->reviewedProposalVersionId = $this->getRecord()->current_proposal_version_id;
    }

    public function isHeadReader(): bool
    {
        return $this->actor()->hasRole(User::StaffRoleAcademicHead)
            && ! $this->actor()->hasAnyRole([User::StaffRoleRegistrar, User::StaffRoleAccounting]);
    }

    public function getExtraBodyAttributes(): array
    {
        return $this->isHeadReader() ? ['class' => 'tala-head-enrollments-page'] : parent::getExtraBodyAttributes();
    }

    /** @var array{sections: Collection<int, Section>, meetings: Collection}|null */
    protected ?array $classChoiceContext = null;

    /** @var array{initial:bool,adjustment:bool,course_drop:?CourseDropRecord,categories:list<string>}|null */
    protected ?array $individualAssessmentContext = null;

    protected ?bool $reservedInitialContext = null;

    /** @var array<string, mixed>|null */
    protected ?array $accountingContext = null;

    /** @var array<string, mixed> */
    private array $accountingMemo = [];

    private function hasCurrentReservedProposal(): bool
    {
        if ($this->reservedInitialContext !== null) {
            return $this->reservedInitialContext;
        }
        $case = $this->getRecord();
        if ($case->canonical_outcome !== Enrollment::OutcomeInProgress || $case->currentProposalVersion === null) {
            return $this->reservedInitialContext = false;
        }
        try {
            app(RegistrationPlacementValidator::class)->assertCurrent($case, $case->currentProposalVersion, requireConfirmation: false);
            app(RegistrationReadinessQuery::class)->assertSourcesCurrent($case, $case->currentProposalVersion);

            return $this->reservedInitialContext = true;
        } catch (ValidationException) {
            return $this->reservedInitialContext = false;
        }
    }

    public function isAccountingWorkspace(): bool
    {
        return false;
    }

    public function focusedAccountingView(): ?string
    {
        if (! $this->actor()->hasRole(User::StaffRoleAccounting)) {
            return null;
        }
        $context = $this->accountingViewData();
        if ($this->enrollmentView === 'changes' || ($this->isAccountingWorkspace() && $this->enrollmentView === 'overview' && ($context['lifecycleStatus']['pending'] ?? 0) > 0)) {
            return 'accounting-lifecycle';
        }
        if ($this->enrollmentView === 'history' && ($context['account'] !== null || ($context['lifecycleStatus']['reviewed'] ?? 0) > 0)) {
            return 'accounting-history';
        }
        if (in_array($this->enrollmentView, ['overview', 'proof'], true) && $this->selectedPaymentProof() !== null) {
            return 'accounting-proof';
        }
        if ($context['assessment'] === null && $this->hasCurrentReservedProposal()) {
            return 'accounting-unassessed';
        }

        return $context['account'] !== null ? 'accounting-account' : 'accounting-awaiting-source';
    }

    /** @return array<string, array<string, mixed>> */
    public function accountingWorkspaceSections(): array
    {
        $context = $this->accountingViewData();
        $sections = [];
        if (($context['lifecycleStatus']['pending'] ?? 0) > 0 || $this->enrollmentView === 'changes') {
            $sections['accounting-lifecycle'] = $this->lifecycleReviewData();
        }
        if ($this->selectedPaymentProof() !== null) {
            $sections['accounting-proof'] = $this->paymentProofViewData();
        }
        if ($context['assessment'] === null && $this->hasCurrentReservedProposal()) {
            $sections['accounting-unassessed'] = [];
        } elseif ($context['account'] !== null && $context['reviewState'] !== 'ordinary') {
            $sections['accounting-account-review'] = $this->lifecycleReviewData();
        } elseif ($context['account'] === null && $sections === []) {
            $sections['accounting-awaiting-source'] = $this->lifecycleReviewData();
        }

        $focused = $this->focusedAccountingView();
        if (isset($sections[$focused])) {
            $sections = [$focused => $sections[$focused]] + $sections;
        }

        return $sections;
    }

    /** @return array<string, mixed> */
    public function accountingViewData(): array
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleAccounting), 403);
        if ($this->accountingContext !== null) {
            return $this->accountingContext;
        }
        $case = $this->getRecord();
        $case->loadMissing([
            'credentialUser',
            'studentProfile.program',
            'admissionApplication.program',
            'term',
            'currentProposalVersion.confirmation',
            'currentProposalVersion.items.section',
            'currentProposalVersion.curriculumVersion.program',
            'currentCorVersion',
            'termAccount.enrollment',
            'termAccount.assessments.lines',
            'termAccount.assessments.obligations.paymentAllocations.payment',
            'termAccount.assessments.obligations.coverages',
            'termAccount.payments.verifier',
            'termAccount.payments.reversals',
            'termAccount.payments.allocations.assessmentObligation',
            'termAccount.coverages.obligation',
            'termAccount.paymentEvidenceVersions',
            'termAccount.financeExports',
        ]);
        $account = $case->termAccount;
        $assessment = $account?->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();
        $position = $account !== null ? app(TermAccountProjection::class)->forAccount($account, useLoadedRelations: true) : null;
        $requirement = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case, $position, $assessment, useLoadedRelations: true);
        $sources = $this->individualAssessmentSources();
        $pending = $this->pendingAdjustment();
        $pending = $pending !== null && (int) $pending->enrollment_id === (int) $case->id ? $pending : null;
        $reviewState = match (true) {
            ! in_array($case->canonical_outcome, [Enrollment::OutcomeInProgress, Enrollment::OutcomeOfficiallyEnrolled], true) => 'ended',
            (bool) ($position['reconciliation_required'] ?? false) => 'reconciliation',
            $sources['course_drop'] !== null => 'drop',
            $pending !== null => 'adjustment',
            $position === null || $position['state'] === 'Unavailable' || $requirement['state'] === 'Unavailable' => 'unavailable',
            default => 'ordinary',
        };

        return $this->accountingContext = [
            'case' => $case, 'proposal' => $case->currentProposalVersion, 'account' => $account, 'assessment' => $assessment,
            'position' => $position, 'requirement' => $requirement, 'reviewState' => $reviewState, 'sources' => $sources,
            'lifecycleStatus' => app(LifecycleAccountingReview::class)->safeStatus($case),
            'recordedAssessment' => $assessment ?? $account?->assessments->sortByDesc('version')->first(),
            'pendingAdjustment' => $pending, 'change' => $pending !== null ? $this->adjustmentChange() : null,
            'adjustmentFinanceReady' => $pending !== null && $pending->state === RegistrationProposalVersion::StateConfirmed && $this->adjustmentFinanceBases() !== [],
            'plan' => $assessment === null ? $this->applicablePublishedFeePlan() : null,
            'hasTranscriptRequests' => $case->studentProfile !== null
                && TranscriptRequest::query()->where('student_profile_id', $case->studentProfile->id)->exists(),
        ];
    }

    /** @return array<string, mixed> */
    public function lifecycleReviewData(): array
    {
        abort_unless($this->isAccountingActor(), 403);
        $case = $this->getRecord();
        $reviews = app(LifecycleAccountingReview::class);

        return [
            'pendingReviews' => $reviews->forEnrollment($case)->paginate(10, pageName: 'changesPage'),
            'reviewHistory' => $reviews->history($case)->paginate(10, pageName: 'reviewHistoryPage'),
            'reviewAccountAvailable' => $reviews->accountMatches($case->termAccount, $case),
        ];
    }

    /**
     * @return array<string, array{title: string, recordLabel: string, amountLabel: string, icon: string, records: LengthAwarePaginator<int, array{kind: string, record: Model, at: mixed}>}>
     */
    public function accountingHistoryGroups(): array
    {
        $account = $this->accountingViewData()['account'];
        $groups = [];
        foreach ([
            'payment' => [$account?->payments, 'paid_at', 'Payments and reversals', 'Payment', 'Amount', 'heroicon-o-banknotes'],
            'proof' => [$account?->paymentEvidenceVersions, 'submitted_at', 'Receipts submitted', 'Receipt', 'Claimed amount', 'heroicon-o-document-magnifying-glass'],
            'assessment' => [$account?->assessments, 'created_at', 'Assessed charges', 'Assessment', 'Amount', 'heroicon-o-document-currency-dollar'],
            'coverage' => [$account?->coverages, 'approved_at', 'Approved coverage', 'Coverage', 'Amount', 'heroicon-o-shield-check'],
            'export' => [$account?->financeExports, 'generated_at', 'Exports', 'Export', 'Document', 'heroicon-o-arrow-down-tray'],
        ] as $kind => [$records, $dateAttribute, $title, $recordLabel, $amountLabel, $icon]) {
            $entries = collect($records ?? [])->map(fn (Model $record): array => [
                'kind' => $kind, 'record' => $record, 'at' => $record->{$dateAttribute} ?? $record->created_at,
            ])->sortByDesc(fn (array $entry): string => ($entry['at']?->format('Y-m-d H:i:s.u') ?? '').'-'.str_pad((string) $entry['record']->id, 20, '0', STR_PAD_LEFT))->values();
            if ($entries->isEmpty()) {
                continue;
            }
            $pageName = 'accountHistory'.Str::studly($kind).'Page';
            $page = min(max(1, (int) $this->getPage($pageName)), max(1, (int) ceil($entries->count() / 10)));
            $groups[$kind] = [
                'title' => $title, 'recordLabel' => $recordLabel, 'amountLabel' => $amountLabel, 'icon' => $icon,
                'records' => new LengthAwarePaginator($entries->forPage($page, 10)->values(), $entries->count(), 10, $page, ['pageName' => $pageName]),
            ];
        }

        return $groups;
    }

    public function recordLifecycleReviewAction(): Action
    {
        return Action::make('recordLifecycleReview')->label('Record review result')->icon('heroicon-o-pencil-square')
            ->visible(fn (): bool => $this->isAccountingActor())
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalHeading(fn (): string => ($this->reviewedLifecycleChange['current_result_id'] ?? null) !== null ? 'Correct review result' : 'Record review result')
            ->modalSubmitActionLabel('Record review result')->modalCancelActionLabel(fn (): string => ($this->reviewedLifecycleChange['current_result_id'] ?? null) !== null ? 'Keep current review' : 'Cancel')
            ->mountUsing(function (Schema $schema, array $arguments): void {
                abort_unless($this->isAccountingActor(), 403);
                $reviews = app(LifecycleAccountingReview::class);
                $this->reviewedLifecycleChange = $reviews->snapshot($this->getRecord(), (int) ($arguments['review'] ?? 0));
                $sources = $reviews->financialSources($this->getRecord());
                $this->reviewedLifecycleFinancialSources = collect($sources)->map(fn ($source): string => $reviews->fingerprint($source))->all();
                $this->lifecycleFinancialSourceOptions = collect($sources)->map(fn ($source, string $key): string => $reviews->financialSourceLabel($key, $source))->all();
                $schema->fill();
            })
            ->schema([
                SchemaView::make('filament.admin.enrollments.lifecycle-review-context')
                    ->viewData(function (): array {
                        $event = app(LifecycleAccountingReview::class)->roots()->with('lifecycleReviewSource')->find($this->reviewedLifecycleChange['event_id'] ?? null);

                        return ['case' => $this->getRecord(), 'source' => $event?->lifecycleReviewSource];
                    }),
                Radio::make('outcome')->label('Review result')->required()->live()
                    ->options(fn (): array => LifecycleAccountingReview::outcomes(($this->reviewedLifecycleChange['current_result_id'] ?? null) !== null)),
                Select::make('financial_source')->label('Existing financial record')->searchable()
                    ->options(fn (): array => $this->lifecycleFinancialSourceOptions)
                    ->visible(fn (Get $get): bool => $get('outcome') === LifecycleAccountingReview::FinancialActionRecorded)
                    ->required(fn (Get $get): bool => $get('outcome') === LifecycleAccountingReview::FinancialActionRecorded)
                    ->helperText('Record any needed charge adjustment, approved coverage, or refund reversal in the student account first, then link that record here. Keep this review pending if no matching financial record exists yet.'),
                Textarea::make('reason')->label(fn (Get $get): string => $get('outcome') === LifecycleAccountingReview::FinancialActionRecorded ? 'How this record addresses the change' : 'Reason')
                    ->required()->maxLength(1000)->rows(3),
                Text::make('This records the review only. Fees, academic records, payment clearance and issued documents remain unchanged.'),
            ])
            ->action(function (array $data): void {
                abort_unless($this->isAccountingActor(), 403);
                try {
                    app(RecordLifecycleAccountingReview::class)->execute($this->getRecord(), $this->actor(), $this->reviewedLifecycleChange, $data, $this->reviewedLifecycleFinancialSources);
                    $this->accountingContext = null;
                    $this->accountingMemo = [];
                    if ($this->isAccountingWorkspace()) {
                        $this->dispatch('accounting-record-updated');
                    }
                    $this->resetPage('changesPage');
                    $this->resetPage('reviewHistoryPage');
                    Notification::make()->title($data['outcome'] === LifecycleAccountingReview::Pending ? 'Review returned to pending' : 'Review result recorded')->success()->send();
                } catch (ValidationException $exception) {
                    SafeUserNotification::sendError('Review result was not recorded', $exception, persistent: true);
                    throw $exception;
                } catch (Throwable $exception) {
                    SafeUserNotification::sendError(
                        'Review result was not recorded',
                        $exception,
                        'Nothing was saved. Keep this review pending and try again, or contact system support.',
                        persistent: true,
                    );
                    throw ValidationException::withMessages(['reason' => 'Nothing was saved. Try again or contact system support.']);
                }
            });
    }

    public function applicablePublishedFeePlan(): ?FeePlan
    {
        $case = $this->getRecord();

        return FeePlan::query()->with(['charges', 'obligations'])
            ->where('program_id', $case->studentProfile?->program_id ?? $case->admissionApplication?->program_id)
            ->where('term_id', $case->term_id)->where('state', FeePlan::StatePublished)->latest('version')->first();
    }

    /** @return array{initial:bool,adjustment:bool,course_drop:?CourseDropRecord,categories:list<string>} */
    private function individualAssessmentSources(): array
    {
        return $this->individualAssessmentContext ??= $this->actor()->hasRole(User::StaffRoleAccounting)
            ? app(IndividualAssessmentSources::class)->forEnrollment($this->getRecord(), useLoadedRelations: true)
            : ['initial' => false, 'adjustment' => false, 'course_drop' => null, 'categories' => []];
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $charges
     * @param  array<string, mixed>  $enrollmentPayment
     * @param  array<array-key, array<string, mixed>>  $laterPayments
     * @return array{chargeTotal: ?int, paymentTotal: ?int}
     */
    private function assessmentDraftTotals(array $charges, array $enrollmentPayment, array $laterPayments): array
    {
        $money = app(DecimalMoney::class);
        $sum = static function (array $rows) use ($money): ?int {
            if ($rows === [] || collect($rows)->contains(fn (array $row): bool => ! preg_match('/^\d+(\.\d{1,2})?$/', (string) ($row['amount'] ?? '')))) {
                return null;
            }

            return collect($rows)->sum(fn (array $row): int => $money->toCents($row['amount']));
        };

        $paymentRows = array_merge([$enrollmentPayment], $laterPayments);

        return [
            'chargeTotal' => $sum($charges),
            'paymentTotal' => $sum($paymentRows),
        ];
    }

    public function focusedRegistrarView(): ?string
    {
        if (! $this->actor()->hasRole(User::StaffRoleRegistrar)) {
            return null;
        }

        $case = $this->getRecord();
        if (! in_array($case->canonical_outcome, [Enrollment::OutcomeOfficiallyEnrolled, Enrollment::OutcomeInProgress], true)) {
            return 'closed';
        }
        if ($this->openImpactReviewOptions() !== [] || $this->needsOverloadAuthority()) {
            return 'review';
        }
        if ($case->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled && $case->currentCorVersion !== null) {
            $proposal = $case->currentProposalVersion;
            $pendingChange = $proposal?->purpose === RegistrationProposalVersion::PurposeAdjustment
                && in_array($proposal->state, [RegistrationProposalVersion::StateDraft, RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed], true)
                && $case->currentCorVersion->registration_proposal_version_id !== $proposal->id;

            return $pendingChange && $this->enrollmentView !== 'official' ? 'adjustment' : 'official';
        }

        if ($case->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled) {
            return 'missing-cor';
        }

        if ($case->canonical_outcome === Enrollment::OutcomeInProgress && $case->currentProposalVersion === null) {
            return 'preparing';
        }

        $readiness = app(RegistrationReadinessQuery::class)->for($case);
        if (! $readiness['identity'] || ! $readiness['eligibility']) {
            return 'requirements';
        }

        if ($case->canonical_outcome === Enrollment::OutcomeInProgress
            && $case->currentProposalVersion?->state === RegistrationProposalVersion::StateDraft) {
            return 'draft';
        }

        if ($case->canonical_outcome === Enrollment::OutcomeInProgress
            && $readiness['ready']) {
            return 'ready';
        }

        if ($case->canonical_outcome === Enrollment::OutcomeInProgress
            && in_array($case->currentProposalVersion?->state, [RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed], true)) {
            if ($readiness['identity'] && $readiness['eligibility']) {
                try {
                    app(RegistrationPlacementValidator::class)->assertCurrent($case, $case->currentProposalVersion, requireConfirmation: false);
                } catch (ValidationException) {
                    return 'placement-recovery';
                }
                $finance = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case);
                if ($finance['state'] === 'Unavailable') {
                    return 'assessment-recovery';
                }
                if ($case->currentProposalVersion->state === RegistrationProposalVersion::StateConfirmed && $finance['state'] !== 'Cleared') {
                    return 'payment-waiting';
                }
            }
        }

        return EnrollmentsTable::stage($case) === 'Waiting for learner' ? 'waiting' : 'requirements';
    }

    public function getHeading(): string
    {
        return $this->focusedAccountingView() !== null ? '' : (string) parent::getHeading();
    }

    public function getTitle(): string
    {
        if ($this->isAccountingActor() && $this->enrollmentView === 'changes') {
            return 'Review student status changes';
        }
        if ($this->isHeadReader()) {
            return $this->getRecord()->credentialUser?->getFilamentName() ?? 'Enrollment record';
        }
        if ($this->focusedAccountingView() === 'accounting-history') {
            return 'Account history';
        }

        if ($this->usesEnrollmentRecordLayout()) {
            return $this->enrollmentView === 'history' ? 'Enrollment history' : 'Enrollment record';
        }

        return $this->focusedRegistrarView() !== null || $this->focusedAccountingView() !== null
            ? ($this->enrollmentView === 'history' ? 'Enrollment history' : ($this->getRecord()->credentialUser?->getFilamentName() ?? 'Enrollment'))
            : parent::getTitle();
    }

    public function getBreadcrumbs(): array
    {
        if ($this->isHeadReader()) {
            return [];
        }

        if ($this->focusedAccountingView() !== null) {
            $queue = array_key_exists($this->returnQueue ?? '', app(ListEnrollments::class)->getTabs()) && $this->returnQueue !== 'overview'
                ? $this->returnQueue : 'accounts';
            $section = match ($this->focusedAccountingView()) {
                'accounting-lifecycle' => 'Student status changes',
                'accounting-history' => 'Account history',
                'accounting-proof' => 'Review receipts',
                default => null,
            };

            return [
                EnrollmentResource::getUrl('index') => 'Overview',
                EnrollmentResource::getUrl('index', ['tab' => 'accounts', ...($queue === 'accounts' ? $this->accountingReturnParameters() : [])]) => 'Student accounts',
                ...($queue === 'accounts' ? [] : [
                    EnrollmentResource::getUrl('index', ['tab' => $queue, ...$this->accountingReturnParameters()]) => app(ListEnrollments::class)->getTabs()[$queue]->getLabel(),
                ]),
                $this->enrollmentUrl(['view' => 'account', 'queue' => $queue]) => $this->getRecord()->credentialUser?->getFilamentName() ?? 'Student account',
                ...($section === null ? [] : [$section]),
            ];
        }

        if ($this->usesEnrollmentRecordLayout()) {
            return [
                EnrollmentResource::getUrl('index', array_filter(['tab' => $this->registrarReturnQueue()])) => 'Students & Enrollment',
                $this->enrollmentUrl() => $this->getRecord()->studentProfile?->student_number ?? $this->getRecord()->case_reference,
            ];
        }

        return $this->focusedRegistrarView() !== null || $this->focusedAccountingView() !== null ? [] : parent::getBreadcrumbs();
    }

    public function getSubheading(): ?string
    {
        return $this->isHeadReader() ? collect([
            $this->getRecord()->studentProfile?->student_number ?? $this->getRecord()->admissionApplication?->application_reference,
            $this->getRecord()->term?->label,
        ])->filter()->implode(' · ') : parent::getSubheading();
    }

    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), ...($this->focusedRegistrarView() !== null || $this->focusedAccountingView() !== null ? ['registrar-enrollment-detail'] : [])];
    }

    public function getRecord(): Enrollment
    {
        $record = parent::getRecord();
        abort_unless($record instanceof Enrollment, 404);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        $actions = $this->enrollmentHeaderActions();

        return $this->usesEnrollmentRecordLayout()
            ? array_values(array_filter($actions, fn ($action): bool => ! ($action instanceof Action && $action->getName() === 'backToEnrollment')))
            : $actions;
    }

    private function usesEnrollmentRecordLayout(): bool
    {
        return $this->actor()->hasRole(User::StaffRoleRegistrar)
            && $this->focusedAccountingView() === null
            && ($this->enrollmentView === 'history' || in_array($this->focusedRegistrarView(), ['official', 'missing-cor'], true));
    }

    private function registrarReturnQueue(): ?string
    {
        if ($this->returnQueue === null || ! $this->actor()->hasRole(User::StaffRoleRegistrar)
            || $this->actor()->hasRole(User::StaffRoleAccounting)) {
            return null;
        }

        return in_array($this->returnQueue, ListEnrollments::REGISTRAR_TAB_KEYS, true) ? $this->returnQueue : null;
    }

    /** @return array<string, mixed> */
    private function accountingReturnParameters(): array
    {
        return [
            ...(filled($this->returnSearch) ? ['search' => $this->returnSearch] : []),
            ...($this->returnFilters !== [] ? ['filters' => $this->returnFilters] : []),
        ];
    }

    /** @param array<string, mixed> $parameters */
    public function enrollmentUrl(array $parameters = []): string
    {
        if ($this->isAccountingActor()) {
            $queue = in_array($this->returnQueue ?? '', ListEnrollments::ACCOUNTING_TAB_KEYS, true) && $this->returnQueue !== 'overview'
                ? $this->returnQueue : 'accounts';

            return EnrollmentResource::getUrl('index', [
                'tab' => $queue,
                'account' => $this->getRecord()->id,
                ...(filled($this->returnSearch) ? ['search' => $this->returnSearch] : []),
                ...($this->returnFilters !== [] ? ['filters' => $this->returnFilters] : []),
                ...array_filter(['proof' => $this->paymentProofId], fn (?int $value): bool => $value !== null),
                'view' => $parameters['view'] ?? ($this->enrollmentView === 'overview' ? 'work' : $this->enrollmentView),
                ...Arr::except($parameters, ['record', 'queue', 'view']),
            ]);
        }

        $queue = $this->registrarReturnQueue();

        return EnrollmentResource::getUrl('view', [
            ...$parameters,
            'record' => $this->getRecord(),
            ...($queue === null ? [] : ['queue' => $queue]),
        ]);
    }

    protected function enrollmentHeaderActions(): array
    {
        if ($this->isHeadReader()) {
            return [Action::make('backToEnrollmentOversight')->label('Back to enrollment oversight')->color('gray')
                ->url(EnrollmentResource::getUrl('index', [
                    'oversightTermId' => $this->oversightTermId,
                    'filters' => $this->returnFilters,
                    'search' => $this->returnSearch,
                ]))];
        }
        if ($this->focusedAccountingView() !== null) {
            return [];
        }
        $focusedView = $this->focusedRegistrarView();
        if ($focusedView !== null && $this->enrollmentView === 'history') {
            return [Action::make('backToEnrollment')->label('Back to enrollment')->icon('heroicon-o-arrow-left')->color('gray')
                ->url($this->enrollmentUrl())];
        }
        if (in_array($focusedView, ['closed', 'preparing', 'waiting', 'payment-waiting', 'assessment-recovery', 'placement-recovery', 'requirements', 'adjustment', 'missing-cor', 'review'], true)) {
            return [Action::make('backToEnrollment')->label('Enrollment')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(EnrollmentResource::getUrl('index', array_filter(['tab' => $this->registrarReturnQueue()])))];
        }

        return [
            ...($focusedView !== null ? [Action::make('backToEnrollment')->label('Enrollment')->tooltip('Back to Enrollment')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(EnrollmentResource::getUrl('index', array_filter(['tab' => $this->registrarReturnQueue()])))] : [$this->nextTaskAction()]),
            Action::make('resendOfficialEnrollmentEmail')
                ->label('Resend enrollment email')
                ->icon('heroicon-o-arrow-path')
                ->tooltip('Send the failed enrollment email again to the student.')
                ->visible(fn (): bool => $this->failedOfficialEnrollmentNotification() instanceof OperationalEvent)
                ->action(function (): void {
                    $event = $this->failedOfficialEnrollmentNotification();
                    if (! $event instanceof OperationalEvent) {
                        return;
                    }

                    try {
                        app(RegistrationNotificationLedger::class)->resend($event, $this->actor());
                        Notification::make()->title('Enrollment email queued again')->success()->send();
                    } catch (Throwable $exception) {
                        $this->failure('Enrollment email was not resent', $exception);
                    }
                }),
            ActionGroup::make([
                $this->recordAcademicEvaluationAction()->color('gray'),
                ...(in_array($focusedView, ['preparing', 'draft'], true) ? [] : [$this->prepareProposalAction()]),
                $this->recordGraduatingOverloadAuthorityAction(),
                ...($focusedView === 'draft' ? [] : [$this->issueProposalAction()]),
                ...($focusedView === 'waiting' ? [] : [$this->recordAssistedConfirmationAction()]),
                ...($focusedView === 'draft' ? [] : [$this->placeProposalAction()]),
                $this->createAssessmentAction(),
                $this->recordIndividualAssessmentAction(),
                $this->reconcileAssessmentEffectsAction(),
                $this->recordApprovedCoverageAction(),
                $this->reverseApprovedCoverageAction(),
                $this->downloadPaymentEvidenceAction(),
                $this->verifyPaymentEvidenceAction(),
                $this->reversePaymentPostingAction(),
                $this->rejectPaymentEvidenceAction(),
                $this->exportTermAccountAction(),
                $this->resolveImpactReviewAction(),
                $this->confirmNoAdditionalCostAction(),
                $this->adjustRegistrationAction(),
                $this->cancelCaseAction(),
                $this->recordLateReopenAuthorityAction(),
                $this->reopenCaseAction(),
                Action::make('printCor')
                    ->label('Print current COR / Save as PDF')
                    ->icon('heroicon-o-printer')
                    ->url(fn (): string => route('cor.print', $this->getRecord()))
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => $focusedView !== 'official' && $this->getRecord()->current_cor_version_id !== null),
                Action::make('printCorHistory')
                    ->label('Certificate copies')
                    ->icon('heroicon-o-document-duplicate')
                    ->visible(fn (): bool => $focusedView !== 'official' && $this->getRecord()->corVersions()->exists())
                    ->url(fn (): string => $this->enrollmentUrl(['view' => 'history']).'#certificate-copies'),
            ])
                ->label($this->actor()->hasRole(User::StaffRoleAccounting) ? 'Account actions and history' : ($focusedView !== null ? 'Enrollment options' : 'Case actions and recovery'))
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->color('gray'),
        ];
    }

    private function nextTaskAction(): Action
    {
        $case = $this->getRecord();
        if ($this->actor()->hasRole(User::StaffRoleAccounting)) {
            $account = $case->termAccount;
            $assessment = $account?->assessments()->where('state', Assessment::StateActive)->latest('version')->first();
            if ($assessment === null) {
                return $case->selection_basis === Enrollment::SelectionIndividuallyAdvised
                    ? $this->recordIndividualAssessmentAction() : $this->createAssessmentAction();
            }
            if (app(TermAccountProjection::class)->forAccount($account)['reconciliation_required'] ?? false) {
                return $this->reconcileAssessmentEffectsAction();
            }
            if ($this->submittedEvidenceOptions() !== []) {
                return $this->verifyPaymentEvidenceAction();
            }

            return $this->recordApprovedCoverageAction();
        }
        if (! $this->actor()->hasRole(User::StaffRoleRegistrar) || $case->canonical_outcome !== Enrollment::OutcomeInProgress) {
            return $this->finalizeOfficialEnrollmentAction();
        }
        if ($case->currentProposalVersion === null) {
            return $this->prepareProposalAction();
        }
        if ($case->currentProposalVersion->state === RegistrationProposalVersion::StateDraft) {
            // #117: TALA finishes a stopped regular case itself once its blocker clears.
            if ($this->regularPreparationBlocker() !== null) {
                return app(RetryStandardRegistrationPreparation::class)->waitingForFeePlan($case)
                    ? Action::make('awaitFeePlan')->label('Waiting for the Fee Plan')->disabled()
                    : $this->retryAutomaticPreparationAction();
            }
            try {
                app(RegistrationPlacementValidator::class)->assertCurrent($case, $case->currentProposalVersion, requireConfirmation: false);
            } catch (ValidationException) {
                return $this->placeProposalAction();
            }
            if (app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case)['state'] === 'Unavailable') {
                return Action::make('awaitAssessment')->label('Waiting for Accounting assessment')->disabled();
            }

            return $this->issueProposalAction();
        }

        $stage = EnrollmentsTable::stage($case);
        if ($stage === 'Placement required') {
            return $this->placeProposalAction();
        }
        if (in_array($stage, ['Payment or fee review', 'Source review required'], true)) {
            return Action::make('awaitCurrentRequirement')->label($stage === 'Payment or fee review' ? 'Waiting for enrollment payment' : 'Current source review required')->disabled();
        }

        return $this->finalizeOfficialEnrollmentAction();
    }

    public function reconcileAssessmentEffectsAction(): Action
    {
        return Action::make('reconcileAssessmentEffects')->label('Match earlier payments and coverage')
            ->icon('heroicon-o-arrows-right-left')
            ->authorize(fn (): bool => $this->isAccountingActor())
            ->visible(function (): bool {
                $account = $this->getRecord()->termAccount;

                return $this->isAccountingActor() && $account !== null
                    && (app(TermAccountProjection::class)->forAccount($account)['reconciliation_required'] ?? false);
            })
            ->modalHeading('Match earlier payments to current schedule')
            ->modalDescription('Choose which current payment due date each earlier payment or coverage should apply toward. Original records stay in account history.')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Record mapping')->modalCancelActionLabel('Keep for review')
            ->modalSubmitAction(fn (Action $action): Action|false => ($this->reviewedReconciliation['sources'] ?? []) !== [] ? $action : false)
            ->fillForm(fn (): array => ['mapping' => collect($this->snapshotReconciliation()['sources'] ?? [])
                ->keys()->map(fn (int $id): array => ['source_obligation_id' => $id])->all()])
            ->schema([
                SchemaView::make('filament.admin.enrollments.accounting-correction-context')
                    ->viewData(fn (): array => $this->reconciliationContext()),
                Repeater::make('mapping')->hiddenLabel()->addable(false)->deletable(false)->reorderable(false)->schema([
                    Hidden::make('source_obligation_id'),
                    Text::make(fn (Get $get): string => $this->reconciliationSourceLine((int) $get('source_obligation_id')))->color('neutral')->weight('semibold'),
                    Select::make('target_obligation_id')->label('Apply to')->placeholder('Choose a current payment')
                        ->options(fn (): array => $this->reconciliationTargetOptions())->required(),
                ]),
                Text::make('Assign every earlier source before recording. This updates how amounts count toward the current assessment; it does not move money or add coverage.')->color('neutral'),
            ])
            ->action(function (array $data): void {
                try {
                    [$assessment, $mapping] = $this->reviewedReconciliationMapping($data['mapping'] ?? []);
                    app(RevalidateAssessmentEffects::class)->execute($assessment, $this->actor(), $mapping, null);
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Earlier payments matched')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Retained effects were not reconciled', $exception);
                }
            });
    }

    private function isAccountingActor(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->canAuthenticate() && $actor->hasRole(User::StaffRoleAccounting);
    }

    private function correctionIdentity(): string
    {
        $case = $this->getRecord();

        return collect([$case->credentialUser?->getFilamentName(), $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference, $case->term?->label])
            ->filter()->implode(' · ');
    }

    /**
     * @param  array<string, string>  $facts
     * @return array{identity:string, lead:?string, facts:array<string,string>, unavailable:?string}
     */
    private function correctionContext(array $facts = [], ?string $lead = null, ?string $unavailable = null): array
    {
        return ['identity' => $this->correctionIdentity(), 'lead' => $lead, 'facts' => $facts, 'unavailable' => $unavailable];
    }

    /** @return array{identity:string, lead:?string, facts:array<string,string>, unavailable:?string} */
    private function individualAssessmentModalContext(): array
    {
        $case = $this->getRecord();
        $proposal = $case->currentProposalVersion;
        $items = $proposal?->items ?? collect();
        $itemCount = $items->count();
        $units = (float) $items->sum('units_snapshot');
        $subjects = $items->isNotEmpty()
            ? $items->map(fn ($item): string => collect([$item->course_code_snapshot, $item->course_title_snapshot, $item->section?->code])->filter()->implode(' · '))->implode('; ')
            : 'No current subjects';

        return $this->correctionContext(
            facts: [
                'Selection' => $case->selection_basis === Enrollment::SelectionIndividuallyAdvised ? 'Individually Advised' : 'Special Term / Exception',
                'Selected load' => $proposal !== null ? $itemCount.' '.Str::plural('subject', $itemCount).' · '.$units.' '.Str::plural('unit', $units) : 'No current proposal',
                'Subjects' => $subjects,
            ],
            lead: 'Review the selected subject load below before entering the school-approved charges and payment dates.',
        );
    }

    private function money(mixed $amount): string
    {
        return AccountingDisplay::money($amount);
    }

    /** @return array<string, mixed> */
    private function snapshotReconciliation(): array
    {
        $account = $this->getRecord()->termAccount;
        $assessment = $account?->assessments()->where('state', Assessment::StateActive)->latest('version')->first();
        $sources = $assessment !== null ? app(TermAccountProjection::class)->retainedEffects($account, $assessment) : [];
        $earlier = AssessmentObligation::query()->with('assessment:id,version')->whereIn('id', array_column($sources, 'id'))->get()->keyBy('id');

        return $this->reviewedReconciliation = $assessment === null || $sources === [] ? [] : [
            'assessment_id' => $assessment->id,
            'version' => $assessment->version,
            'total' => $this->money($assessment->total),
            'context_fingerprint' => $this->reconciliationAssessmentFingerprint($assessment),
            'sources' => collect($sources)->mapWithKeys(fn (array $source): array => [$source['id'] => [
                'fingerprint' => $this->retainedSourceFingerprint($source),
                'line' => 'Earlier assessment · '.$source['label']
                    .' · payment '.$this->money($source['payment'] / 100).' · coverage '.$this->money($source['committed_coverage'] / 100),
            ]])->all(),
        ];
    }

    private function reconciliationAssessmentFingerprint(Assessment $assessment): string
    {
        $facts = Arr::except($assessment->getAttributes(), ['created_at', 'updated_at']);
        $facts['obligations'] = $assessment->obligations()->orderBy('id')->get()
            ->map(fn (AssessmentObligation $obligation): array => Arr::except($obligation->getAttributes(), ['created_at', 'updated_at']))->all();

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $source */
    private function retainedSourceFingerprint(array $source): string
    {
        return hash('sha256', json_encode([$source['id'], $source['payment'], $source['committed_coverage']], JSON_THROW_ON_ERROR));
    }

    /** @return array{identity:string, lead:?string, facts:array<string,string>, unavailable:?string} */
    private function reconciliationContext(): array
    {
        if (($this->reviewedReconciliation['sources'] ?? []) === []) {
            return $this->correctionContext(unavailable: 'No retained payment or coverage needs a target in the current assessment. Close this form and review the account.');
        }

        return $this->correctionContext(['Current charges' => $this->reviewedReconciliation['total'], 'Earlier sources to assign' => (string) count($this->reviewedReconciliation['sources'])]);
    }

    private function reconciliationSourceLine(int $sourceId): string
    {
        return $this->reviewedReconciliation['sources'][$sourceId]['line'] ?? 'Retained source unavailable';
    }

    /** @return array<int, string> */
    private function reconciliationTargetOptions(): array
    {
        return AssessmentObligation::query()->where('assessment_id', $this->reviewedReconciliation['assessment_id'] ?? 0)->orderBy('id')->get()
            ->mapWithKeys(fn (AssessmentObligation $obligation): array => [
                $obligation->id => $obligation->label.' ('.$this->money($obligation->amount).')',
            ])->all();
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array{0: Assessment, 1: array<int, int>}
     */
    private function reviewedReconciliationMapping(array $rows): array
    {
        $reviewed = $this->reviewedReconciliation;
        $stale = fn (string $message): ValidationException => ValidationException::withMessages(['mapping' => $message]);
        $account = $this->getRecord()->termAccount;
        $current = $account?->assessments()->where('state', Assessment::StateActive)->latest('version')->first();
        if ($reviewed === [] || $account === null || $current === null || $current->id !== ($reviewed['assessment_id'] ?? null)
            || ! hash_equals($reviewed['context_fingerprint'] ?? '', $this->reconciliationAssessmentFingerprint($current))) {
            throw $stale('The current assessment changed after you opened this form. Close it and review the account again.');
        }
        $sources = collect(app(TermAccountProjection::class)->retainedEffects($account, $current))
            ->mapWithKeys(fn (array $source): array => [$source['id'] => $this->retainedSourceFingerprint($source)]);
        if ($sources->all() !== collect($reviewed['sources'])->map(fn (array $source): string => $source['fingerprint'])->all()) {
            throw $stale('The retained payment or coverage changed after you opened this form. Close it and review the account again.');
        }
        $rows = array_values($rows);
        $ids = array_map(fn (array $row): int => (int) ($row['source_obligation_id'] ?? 0), $rows);
        if (count($ids) !== $sources->count() || count(array_unique($ids)) !== count($ids) || array_diff($ids, $sources->keys()->all()) !== []) {
            throw $stale('Choose exactly one target for every retained source listed.');
        }
        $targets = array_map(fn (array $row): int => (int) ($row['target_obligation_id'] ?? 0), $rows);
        if (array_diff($targets, $current->obligations()->pluck('id')->all()) !== []) {
            throw $stale('Every target must be an obligation of the current assessment.');
        }

        return [$current, array_combine($ids, $targets)];
    }

    private function evidenceTargetSummary(mixed $evidenceId): string
    {
        $evidence = $this->getRecord()->termAccount?->paymentEvidenceVersions()->find($evidenceId);
        if (! $evidence instanceof PaymentEvidenceVersion) {
            return 'Select the evidence to inspect its captured assessment and named obligations before verification.';
        }
        $targets = collect($evidence->obligation_snapshot ?? []);

        return 'Captured assessment '.($evidence->assessment_id ?? 'unavailable').'. Named targets: '.($targets->isEmpty()
            ? 'Unavailable; obtain current source review before posting.'
            : $targets->map(fn (array $target): string => ($target['label'] ?? $target['code'] ?? 'Obligation '.($target['id'] ?? $target['assessment_obligation_id'] ?? '')).' · '.(isset($target['available_balance']) ? $this->money($target['available_balance']) : 'Captured amount unavailable'))->implode('; '))
            .($evidence->possible_duplicate ? ' Possible duplicate: compare the original external source before posting.' : '');
    }

    public function finalizeOfficialEnrollmentAction(): Action
    {
        return Action::make('finalizeOfficialEnrollment')
            ->label('Finalize enrollment')
            ->icon('heroicon-o-check-badge')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Finalize enrollment')
            ->modalIcon('heroicon-o-check-badge')
            ->modalAlignment(Alignment::Start)
            ->modalDescription('This makes the subjects official and issues the Certificate of Registration. Student access is enabled for a first enrollment. Current requirements are checked again when you finalize.')
            ->modalSubmitActionLabel('Finalize enrollment')
            ->modalWidth(Width::ThreeExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalContent(fn () => view('filament.admin.enrollments.finalization-context', ['case' => $this->getRecord()]))
            ->fillForm(fn (): array => ['reviewed_proposal_id' => $this->getRecord()->current_proposal_version_id])
            ->schema([
                Hidden::make('reviewed_proposal_id')->required(),
            ])
            ->authorize(fn (): bool => auth()->user()?->can('officiallyEnroll', $this->getRecord()) ?? false)
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress && app(RegistrationReadinessQuery::class)->for($this->getRecord())['ready'])
            ->action(function (array $data): void {
                $actor = $this->actor();

                try {
                    $this->record = app(FinalizeOfficialEnrollment::class)->execute(
                        $this->getRecord(),
                        $actor,
                        expectedProposalVersionId: (int) $data['reviewed_proposal_id'],
                    );
                    Notification::make()
                        ->title('Official enrollment finalized')
                        ->body('The subjects are official and the Certificate of Registration is ready.')
                        ->success()
                        ->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Official enrollment was not finalized', $exception);
                }
            });
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, RegistrationCreditEvaluation> */
    public function academicEvaluations(): \Illuminate\Database\Eloquent\Collection
    {
        abort_unless($this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar), 403);

        return RegistrationCreditEvaluation::query()->where('enrollment_id', $this->getRecord()->id)->latest('id')->get();
    }

    /** @return list<array{title:string,description:string,action:?string,url:?string}> */
    public function registrarRequirements(): array
    {
        abort_unless($this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar), 403);

        $case = $this->getRecord();
        $readiness = app(RegistrationReadinessQuery::class)->for($case);
        $requirements = [];
        if (! $readiness['identity']) {
            $activeAccount = $case->credentialUser?->canAuthenticate() ?? false;
            $requirements[] = [
                'title' => $activeAccount ? 'Check admissions clearance' : 'Student account is inactive',
                'description' => $activeAccount
                    ? 'Enrollment clearance is missing or no longer matches the applicant’s details. Check the current admissions record.'
                    : 'Ask the System Administrator to restore account access before continuing enrollment.',
                'action' => null,
                'url' => $activeAccount && $case->admissionApplication !== null
                    && AdmissionApplicationResource::canView($case->admissionApplication)
                        ? AdmissionApplicationResource::getUrl('view', ['record' => $case->admissionApplication]) : null,
            ];
        }
        if (in_array('Academic evaluation required', $readiness['blockers'], true)) {
            $requirements[] = [
                'title' => 'Record transfer credit evaluation',
                'description' => 'Record the school’s approved credit result, including a no-credit result when applicable. Then revise the subject selection to use that evaluation.',
                'action' => 'recordAcademicEvaluation',
                'url' => null,
            ];
        } elseif (! $readiness['eligibility'] && $readiness['identity']) {
            $requirements[] = [
                'title' => 'Review subject eligibility',
                'description' => 'Check the current curriculum, prerequisites and academic restrictions. Once the school records are up to date, revise the selected subjects.',
                'action' => 'prepareProposal',
                'url' => null,
            ];
        }

        return $requirements !== [] ? $requirements : [[
            'title' => 'Prepare updated subjects',
            'description' => 'The earlier selection cannot be used for confirmation. Prepare subjects using the current curriculum and available classes.',
            'action' => 'prepareProposal',
            'url' => null,
        ]];
    }

    public function recordAcademicEvaluationAction(): Action
    {
        return Action::make('recordAcademicEvaluation')->label('Credit subjects')->icon('heroicon-o-academic-cap')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress
                && $this->getRecord()->admissionApplication?->application_path === AdmissionApplication::PathTransferee)
            ->modalHeading('Credit transferee subjects')->modalWidth(Width::Screen)->extraModalWindowAttributes(['class' => 'tala-crediting-modal'])
            ->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('Compare the transferee’s TOR with the SIA curriculum. Choose Credited or Need to take for each subject; record the TOR grade and previous course for credited subjects. Leave every subject on Need to take for a no-credit result.')
            ->modalSubmitActionLabel('Save crediting')->modalCancelActionLabel('Cancel')
            ->fillForm(function (): array {
                $case = $this->getRecord()->fresh();
                $latest = RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->latest('id')->first();
                $this->reviewedAcademicEvaluation = ['submission' => (int) $case->admissionApplication->current_submission_version_id,
                    'lock' => $case->lock_version, 'evaluation' => $latest?->id];
                $curriculumId = $latest?->curriculum_version_id
                    ?? app(RegistrationAcademicEligibilityQuery::class)->resolveStudentCurriculum($case)?->id
                    ?? $this->activeTransfereeCurriculumIds()->first();

                return ['curriculum_version_id' => $curriculumId,
                    'same_curriculum' => TransferCreditingForm::sameCurriculumState($latest),
                    'rows' => TransferCreditingForm::rowsState($curriculumId, $latest, $latest?->same_curriculum === true),
                    'authority_reference' => null, 'approved_on' => null];
            })
            ->schema(fn (): array => TransferCreditingForm::withTorPreview([
                Text::make(fn (): string => $this->getRecord()->credentialUser->getFilamentName().' · '.$this->getRecord()->admissionApplication->program->name.' · '.$this->getRecord()->term->label),
                Select::make('curriculum_version_id')->label('SIA curriculum')->required()->live()
                    ->options(function (): array {
                        $case = $this->getRecord();
                        $programId = $case->admissionApplication?->program_id;
                        $preferredCurriculumId = app(RegistrationAcademicEligibilityQuery::class)->resolveStudentCurriculum($case)?->id;

                        return CurriculumVersion::query()
                            ->with('academicYears')
                            ->where('program_id', $programId)
                            ->whereIn('state', [CurriculumVersion::StateActive, CurriculumVersion::StateSuperseded])
                            ->orderByDesc('id')
                            ->get()
                            ->sortBy(fn (CurriculumVersion $curriculum): array => [
                                $curriculum->id === $preferredCurriculumId ? 0 : 1,
                                $curriculum->state === CurriculumVersion::StateActive ? 0 : 1,
                                -$curriculum->id,
                            ])
                            ->mapWithKeys(fn (CurriculumVersion $curriculum): array => [
                                $curriculum->id => $curriculum->batchLabel(),
                            ])
                            ->all();
                    })
                    ->afterStateUpdated(fn (Get $get, Set $set, mixed $state) => $set('rows', TransferCreditingForm::rowsState(filled($state) ? (int) $state : null, null, $get('same_curriculum') === 'same'))),
                ...TransferCreditingForm::components(fn (Get $get): ?int => filled($get('curriculum_version_id')) ? (int) $get('curriculum_version_id') : null),
            ], $this->getRecord()->admissionApplication))->action(function (array $data, Action $action): void {
                $review = $this->reviewedAcademicEvaluation;
                if ($review === null) {
                    throw ValidationException::withMessages(['evaluation' => 'Reopen the academic evaluation.']);
                }
                $lines = TransferCreditingForm::creditLines($data);
                try {
                    app(RecordRegistrationCreditEvaluation::class)->execute($this->getRecord(), $this->actor(),
                        array_keys($lines), $data['authority_reference'], CarbonImmutable::parse($data['approved_on']), $review['submission'],
                        (int) $data['curriculum_version_id'], $review['lock'], $review['evaluation'],
                        creditLines: $lines, sameCurriculum: TransferCreditingForm::sameCurriculum($data));
                } catch (ValidationException $exception) {
                    Notification::make()->danger()->title('Crediting was not saved')->body(collect($exception->errors())->flatten()->first())->send();
                    $action->halt();
                }
                Notification::make()->success()->title('Academic evaluation recorded')
                    ->body(count($lines) === 0 ? 'No subjects credited.' : count($lines).' subject(s) credited.')->send();
                $this->getRecord()->refresh();
            });
    }

    /** @return Collection<int, int> */
    private function activeTransfereeCurriculumIds(): Collection
    {
        return CurriculumVersion::query()->where('program_id', $this->getRecord()->admissionApplication?->program_id)
            ->where('state', CurriculumVersion::StateActive)->orderBy('id')->pluck('id');
    }

    public function prepareProposalAction(): Action
    {
        $hasSuggestion = $this->getRecord()->currentProposalVersion === null && ($this->advisedSuggestion()['section_ids'] ?? []) !== [];

        return Action::make('prepareProposal')
            ->label($this->getRecord()->currentProposalVersion === null ? ($hasSuggestion ? 'Adjust' : 'Choose subjects') : 'Revise subjects')
            ->color($this->getRecord()->currentProposalVersion === null && ! $hasSuggestion ? 'primary' : 'gray')
            ->icon('heroicon-o-document-plus')
            ->modalHeading('Choose subjects')
            ->modalDescription(fn (): string => ($this->getRecord()->credentialUser?->getFilamentName() ?? 'Student').' · '.$this->getRecord()->term?->label.'. '.($this->getRecord()->currentProposalVersion === null
                ? 'Saving prepares a draft. Seats and assessment are checked next.'
                : 'Saving a changed selection replaces these subjects and releases their temporary class places. Charges must be checked and the student must confirm again.'))
            ->modalSubmitActionLabel('Save subjects')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal registrar-subject-selection-modal'])
            ->authorize(fn (): bool => $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress
                && ! ($this->getRecord()->selection_basis === Enrollment::SelectionStandardCurriculum && $this->getRecord()->currentProposalVersion === null)
                && ($this->actor()->can('confirmPlacement', $this->getRecord())))
            ->fillForm(function (): array {
                $availableSectionIds = $this->classChoices()['sections']
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();
                $prefilledSectionIds = $this->getRecord()->currentProposalVersion?->items->pluck('section_id')->all()
                    ?? $this->submittedIrregularRequest()?->sectionIds()
                    ?? $this->advisedSuggestion()['section_ids']
                    ?? [];
                $usesSuggestion = $this->getRecord()->currentProposalVersion === null && $prefilledSectionIds !== [];

                return [
                    'case_version' => $this->getRecord()->lock_version,
                    'section_ids' => array_values(array_intersect(array_map('intval', $prefilledSectionIds), $availableSectionIds)),
                    'subject_year_filter' => null,
                    'subject_term_filter' => $usesSuggestion ? null : $this->defaultSubjectTermFilter(),
                    'curriculum_position' => $usesSuggestion ? ($this->advisedSuggestion()['curriculum_position'] ?? null) : null,
                ];
            })
            ->schema([
                Hidden::make('case_version')->required(),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('subject_year_filter')
                        ->label('Year level')
                        ->placeholder('All year levels')
                        ->options(fn (): array => $this->subjectPositionFilterOptions('year'))
                        ->live()
                        ->dehydrated(false),
                    Select::make('subject_term_filter')
                        ->label('Curriculum term')
                        ->placeholder('All terms')
                        ->options(fn (): array => $this->subjectPositionFilterOptions('term'))
                        ->live()
                        ->dehydrated(false),
                ]),
                CheckboxList::make('section_ids')
                    ->label('Subjects and classes')
                    ->options(fn (Get $get): array => $this->filteredSectionOptions($get('subject_year_filter'), $get('subject_term_filter'), $get('section_ids') ?? []))
                    ->descriptions(fn (): array => $this->sectionDescriptions())
                    ->searchable()
                    ->searchPrompt('Find a subject or class')
                    // Select all ticks only the classes the year, term and search filters leave visible.
                    ->bulkToggleable()
                    ->columns(1)
                    ->required()
                    ->live()
                    ->helperText(fn (): string => $this->getRecord()->currentProposalVersion !== null ? 'A changed selection needs a new student confirmation.' : 'Choose the subjects to include in this enrollment.'),
                Text::make(fn (Get $get): string => $this->selectedSubjectSummary($get('section_ids') ?? [])),
                Select::make('curriculum_position')
                    ->label('Curriculum term for the normal unit load')
                    ->options(fn (Get $get): array => $this->curriculumPositionOptions($get('section_ids') ?? []))
                    ->required(fn (Get $get): bool => $this->needsCurriculumPosition($get('section_ids') ?? []))
                    ->visible(fn (Get $get): bool => $this->needsCurriculumPosition($get('section_ids') ?? []))
                    ->helperText('The selected subjects span curriculum terms. Choose the term that defines the normal unit load.'),
            ])
            ->action(function (array $data): void {
                try {
                    $proposal = app(PrepareRegistrationProposal::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        array_map('intval', $data['section_ids']),
                        (int) $data['case_version'],
                        curriculumPosition: $data['curriculum_position'] ?? null,
                    );
                    try {
                        app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $this->actor());
                        $this->record = $this->getRecord()->refresh();
                        Notification::make()->title('Subjects saved and seats reserved')->success()->send();
                    } catch (ValidationException|CalendarGateViolation $reservationException) {
                        $this->record = $this->getRecord()->refresh();
                        $reason = $reservationException instanceof ValidationException
                            ? collect($reservationException->errors())->flatten()->first()
                            : $reservationException->getMessage();
                        Notification::make()
                            ->title('Subjects saved for review')
                            ->body($reason ?: 'Seats could not be reserved yet. Review the subjects and reserve seats when ready.')
                            ->warning()
                            ->send();
                    }
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Subjects were not saved', $exception);
                }
            });
    }

    /**
     * #103: subjects TALA suggests for this Individually Advised case (nothing reserved yet).
     *
     * @return array<string, mixed>|null
     */
    public function advisedSuggestion(): ?array
    {
        $case = $this->getRecord();
        $key = $case->id.':'.$case->lock_version.':'.$case->current_proposal_version_id;

        if (! array_key_exists($key, $this->advisedSuggestions)) {
            $this->advisedSuggestions[$key] = app(AdvisedSubjectSuggestion::class)->forCase($case);
        }

        return $this->advisedSuggestions[$key];
    }

    public function approveSuggestedSubjectsAction(): Action
    {
        return Action::make('approveSuggestedSubjects')
            ->label('Approve subjects')
            ->icon('heroicon-o-check')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-check-circle')
            ->modalIconColor('primary')
            ->modalHeading('Approve these subjects?')
            ->modalDescription('TALA reserves a seat in each class and sends the case to Accounting for the assessment. The subjects go to the student once the assessment is recorded.')
            ->modalSubmitActionLabel('Approve and reserve seats')
            ->authorize(fn (): bool => $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->visible(fn (): bool => ($this->advisedSuggestion()['section_ids'] ?? []) !== []
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->action(function (): void {
                $suggestion = $this->advisedSuggestion();
                if (($suggestion['section_ids'] ?? []) === []) {
                    return;
                }

                try {
                    $proposal = app(PrepareRegistrationProposal::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        $suggestion['section_ids'],
                        (int) $this->getRecord()->lock_version,
                        curriculumPosition: $suggestion['curriculum_position'],
                    );
                } catch (Throwable $exception) {
                    $this->failure('Subjects were not approved', $exception);

                    return;
                }

                try {
                    app(PlaceRegistrationProposal::class)->execute($proposal->fresh(), $this->actor());
                    Notification::make()
                        ->title('Subjects approved and seats reserved')
                        ->body('The case is now with Accounting for the assessment.')
                        ->success()
                        ->send();
                } catch (ValidationException|CalendarGateViolation $reservationException) {
                    $reason = $reservationException instanceof ValidationException
                        ? collect($reservationException->errors())->flatten()->first()
                        : $reservationException->getMessage();
                    Notification::make()
                        ->title('Subjects approved; seats not reserved yet')
                        ->body($reason ?: 'Review the subjects and reserve seats when ready.')
                        ->warning()
                        ->send();
                }

                $this->advisedSuggestions = [];
                $this->record = $this->getRecord()->refresh();
                $this->redirect($this->enrollmentUrl());
            });
    }

    /**
     * Why automatic preparation has not prepared this regular case yet.
     *
     * @return array{reason: string, resolved: bool}|null
     */
    public function regularPreparationBlocker(): ?array
    {
        $case = $this->getRecord();
        $key = $case->id.':'.$case->lock_version.':'.$case->current_proposal_version_id;

        if (! array_key_exists($key, $this->regularPreparationBlockers)) {
            $this->regularPreparationBlockers[$key] = app(RetryStandardRegistrationPreparation::class)->blocker($case);
        }

        return $this->regularPreparationBlockers[$key];
    }

    public function retryAutomaticPreparationAction(): Action
    {
        return Action::make('retryAutomaticPreparation')
            ->label('Retry automatic preparation')
            ->icon('heroicon-o-arrow-path')
            ->authorize(fn (): bool => $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->visible(fn (): bool => $this->regularPreparationBlocker() !== null
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->action(function (): void {
                try {
                    $service = app(RetryStandardRegistrationPreparation::class);
                    $prepared = $service->retry($this->getRecord(), $this->actor());
                    $this->record = $prepared;
                    $this->regularPreparationBlockers = [];

                    if ($service->wasSentToStudent($prepared)) {
                        Notification::make()
                            ->title('Subjects prepared and sent to the student')
                            ->body('Seats are reserved, the subjects are confirmed and the assessment is ready. Payment completes enrollment.')
                            ->success()
                            ->send();
                        $this->redirect($this->enrollmentUrl());

                        return;
                    }

                    Notification::make()
                        ->title('Still not prepared')
                        ->body($service->blocker($prepared)['reason'] ?? 'Automatic preparation did not finish. Choose the subjects manually.')
                        ->warning()
                        ->send();
                } catch (Throwable $exception) {
                    $this->failure('Automatic preparation did not run', $exception);
                }
            });
    }

    /** @return array<string, mixed>|null */
    public function preparingIrregularReview(): ?array
    {
        $case = $this->getRecord();
        if ($case->studentProfile === null) {
            return null;
        }

        return app(IrregularStudentReasons::class)->forStudent($case->studentProfile, $case->term);
    }

    /** #99 Scope 11: the student's irregular application waiting for this Registrar. */
    private function submittedIrregularRequest(): ?IrregularEnrollmentRequest
    {
        return IrregularEnrollmentRequest::query()
            ->where('enrollment_id', $this->getRecord()->id)
            ->where('state', IrregularEnrollmentRequest::StateSubmitted)
            ->first();
    }

    public function returnIrregularRequestAction(): Action
    {
        return Action::make('returnIrregularRequest')
            ->label('Return for correction')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->authorize(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar) && $this->submittedIrregularRequest() !== null)
            ->modalHeading('Return the irregular application')
            ->modalDescription('The student can edit their subjects and submit again.')
            ->schema([
                Textarea::make('return_reason')->label('What should the student correct?')->required()->rows(3)->maxLength(1000),
            ])
            ->modalSubmitActionLabel('Return to student')
            ->action(function (array $data): void {
                $request = $this->submittedIrregularRequest();
                abort_unless($request instanceof IrregularEnrollmentRequest, 404);
                try {
                    app(ReturnIrregularEnrollmentRequest::class)->execute($request, $this->actor(), (string) $data['return_reason']);
                    Notification::make()->title('Application returned to the student')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('The application was not returned', $exception);
                }
            });
    }

    public function downloadIrregularDocumentsAction(): Action
    {
        return Action::make('downloadIrregularDocuments')
            ->label('Download supporting documents')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->link()
            ->authorize(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->action(function () {
                $request = IrregularEnrollmentRequest::query()->where('enrollment_id', $this->getRecord()->id)->first();
                $paths = collect($request?->supporting_documents ?? [])->filter(fn (string $path): bool => str_starts_with($path, 'irregular-requests/') && Storage::disk('local')->exists($path));
                abort_if($paths->isEmpty(), 404);
                if ($paths->count() === 1) {
                    return Storage::disk('local')->download($paths->first());
                }

                $zipPath = tempnam(sys_get_temp_dir(), 'irreg').'.zip';
                $zip = new \ZipArchive;
                $zip->open($zipPath, \ZipArchive::CREATE);
                $paths->each(fn (string $path) => $zip->addFile(Storage::disk('local')->path($path), basename($path)));
                $zip->close();

                return response()->download($zipPath, 'irregular-application-'.$this->getRecord()->id.'.zip')->deleteFileAfterSend();
            });
    }

    public function recordGraduatingOverloadAuthorityAction(): Action
    {
        return Action::make('recordGraduatingOverloadAuthority')
            ->label('Record overload approval')
            ->icon('heroicon-o-document-check')
            ->modalHeading('Record overload approval')
            ->modalDescription('Record the school’s existing approval for these subjects. This does not grant approval.')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalSubmitActionLabel('Record approval')->modalCancelActionLabel('Keep under review')
            ->fillForm(fn (): array => ['proposal_id' => $this->getRecord()->current_proposal_version_id, 'reviewed_source' => $this->overloadFingerprint()])
            ->modalContent(fn () => view('filament.admin.enrollments.review-source-context', ['review' => $this->registrarReviewData()]))
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->getRecord()->studentProfile?->academic_standing === StudentProfile::StandingGraduationCandidate
                && $this->getRecord()->currentProposalVersion?->state === RegistrationProposalVersion::StateDraft
                && data_get($this->getRecord()->currentProposalVersion->source_snapshot, 'unit_load.requires_graduating_overload') === true)
            ->schema([
                Hidden::make('proposal_id')->required(),
                Hidden::make('reviewed_source')->required(),
                TextInput::make('authority_reference')
                    ->label('Approval memo or reference')
                    ->placeholder('e.g., Memo 2026-014 or approving office')
                    ->required()
                    ->maxLength(255),
                DatePicker::make('authority_date')
                    ->label('Approval date')
                    ->required()
                    ->maxDate(now())
                    ->helperText('Your name, the time and the reviewed subjects are recorded automatically.'),
            ])
            ->action(function (array $data): void {
                try {
                    if (! hash_equals($this->overloadFingerprint(), (string) $data['reviewed_source']) || (int) $data['proposal_id'] !== (int) $this->getRecord()->fresh()->current_proposal_version_id) {
                        throw ValidationException::withMessages(['proposal_id' => 'The reviewed proposal or current curriculum load changed. Review it again before recording approval.']);
                    }
                    app(RecordGraduatingOverloadAuthority::class)->execute(
                        RegistrationProposalVersion::query()->where('enrollment_id', $this->getRecord()->id)->findOrFail($data['proposal_id']),
                        $this->actor(),
                        [
                            'authority_reference' => $data['authority_reference'],
                            'authority_date' => $data['authority_date'],
                        ],
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()
                        ->title('Graduating overload authority recorded')
                        ->body('The authority applies only to this exact Draft proposal and unit-load check.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    $this->failure('Graduating overload authority was not recorded', $exception);
                }
            });
    }

    public function issueProposalAction(): Action
    {
        return Action::make('issueProposal')
            ->label('Send subjects')
            ->icon('heroicon-o-paper-airplane')
            ->modal(false)
            ->visible(fn (): bool => $this->getRecord()->currentProposalVersion?->state === RegistrationProposalVersion::StateDraft
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->authorize(fn (): bool => $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->action(function (): void {
                try {
                    $proposal = $this->reviewedProposalVersionId === null ? null : $this->getRecord()->proposalVersions()->find($this->reviewedProposalVersionId);
                    if ($proposal === null || (int) $this->getRecord()->fresh()->current_proposal_version_id !== $this->reviewedProposalVersionId) {
                        throw ValidationException::withMessages(['proposal' => 'The reviewed subjects changed. Open the current proposal before sending it.']);
                    }
                    $sent = app(IssueRegistrationProposal::class)->execute($proposal, $this->actor());
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()
                        ->title($sent->state === RegistrationProposalVersion::StateConfirmed ? 'Subjects sent and confirmed' : 'Subjects sent for confirmation')
                        ->success()
                        ->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Subjects were not sent', $exception);
                }
            });
    }

    public function recordAssistedConfirmationAction(): Action
    {
        return Action::make('recordAssistedConfirmation')
            ->label('Record confirmation')
            ->icon('heroicon-o-check')
            ->modalHeading('Record student confirmation')
            ->modalDescription('Record only confirmation the student has already given for these reviewed subjects. Once payment is cleared, TALA enrolls the student automatically.')
            ->modalSubmitActionLabel('Record confirmation')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->visible(fn (): bool => $this->getRecord()->currentProposalVersion?->state === RegistrationProposalVersion::StateIssued
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->authorize(fn (): bool => $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->modalCancelActionLabel('Keep for review')
            ->modalContent(fn () => $this->pendingAdjustment() ? view('filament.admin.enrollments.change-confirmation-context', $this->adjustmentViewData()) : view('filament.admin.enrollments.confirmation-context', [
                'case' => $this->getRecord(), 'proposal' => $this->getRecord()->currentProposalVersion,
            ]))
            ->schema([
                Hidden::make('proposal_id')->default(fn (): ?int => $this->getRecord()->current_proposal_version_id)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(ConfirmRegistrationProposal::class)->execute(
                        RegistrationProposalVersion::query()->where('enrollment_id', $this->getRecord()->id)->findOrFail($data['proposal_id']),
                        $this->getRecord()->credentialUser,
                        $this->actor(),
                        null,
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Assisted confirmation recorded')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Confirmation was not recorded', $exception);
                }
            });
    }

    public function placeProposalAction(): Action
    {
        return Action::make('placeProposal')
            ->label('Reserve seats')
            ->icon('heroicon-o-map-pin')
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress && in_array($this->getRecord()->currentProposalVersion?->state, [RegistrationProposalVersion::StateDraft, RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed], true)
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->modal(false)
            ->authorize(fn (): bool => $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->action(function (): void {
                try {
                    $proposal = $this->reviewedProposalVersionId === null ? null : $this->getRecord()->proposalVersions()->find($this->reviewedProposalVersionId);
                    if ($proposal === null || (int) $this->getRecord()->fresh()->current_proposal_version_id !== $this->reviewedProposalVersionId) {
                        throw ValidationException::withMessages(['proposal' => 'The reviewed subjects changed. Open the current proposal before reserving seats.']);
                    }
                    app(PlaceRegistrationProposal::class)->execute(
                        $proposal,
                        $this->actor(),
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Seats reserved')->success()->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Seats were not reserved', $exception);
                }
            });
    }

    public function createAssessmentAction(): Action
    {
        return Action::make('createAssessment')
            ->label('Apply standard fee plan')
            ->icon('heroicon-o-document-currency-dollar')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->hasCurrentReservedProposal() && $this->applicablePublishedFeePlan() !== null)
            ->authorize(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting))
            ->modalHeading('Apply standard fee plan')
            ->modalDescription('Check the approved charges and payment dates before recording this assessment.')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalSubmitActionLabel('Record assessment')
            ->fillForm(fn (): array => [
                'reviewed_proposal_id' => $this->getRecord()->current_proposal_version_id,
                'reviewed_fee_plan_id' => $this->applicablePublishedFeePlan()?->id,
            ])
            ->schema([
                Hidden::make('reviewed_proposal_id')->required(),
                Hidden::make('reviewed_fee_plan_id')->required(),
                SchemaView::make('filament.admin.enrollments.fee-plan-confirmation')->viewData(fn (Get $get): array => [
                    'case' => $this->getRecord(),
                    'plan' => FeePlan::query()->with(['charges', 'obligations'])
                        ->where('program_id', $this->getRecord()->studentProfile?->program_id ?? $this->getRecord()->admissionApplication?->program_id)
                        ->where('term_id', $this->getRecord()->term_id)->find($get('reviewed_fee_plan_id')),
                ]),
            ])
            ->action(function (array $data): void {
                try {
                    app(CreateAssessmentFromPublishedFeePlan::class)->execute(
                        $this->getRecord(), $this->actor(),
                        expectedProposalVersionId: (int) $data['reviewed_proposal_id'],
                        expectedFeePlanId: (int) $data['reviewed_fee_plan_id'],
                    );
                    $this->record = $this->getRecord()->refresh();
                    $this->reservedInitialContext = null;
                    Notification::make()->title('Assessment recorded')->success()->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Assessment was not recorded', $exception);
                }
            });
    }

    public function recordIndividualAssessmentAction(): Action
    {
        $sources = $this->individualAssessmentSources();
        $categories = collect($sources['categories'])->mapWithKeys(fn (string $category): array => [$category => Str::headline($category)])->all();
        $categoryFields = count($categories) === 1 ? [
            Hidden::make('category')->required(),
            Text::make('Assessment type: '.(reset($categories) ?: 'Unavailable'))->columnSpanFull(),
        ] : [
            Select::make('category')->label('Assessment type')->options($categories)->required()->live()->columnSpanFull(),
        ];

        return Action::make('recordIndividualAssessment')
            ->label('Enter custom assessment')
            ->icon('heroicon-o-document-currency-dollar')
            ->modalHeading('Custom assessment')
            ->modalDescription('Enter school-approved custom charges and payment dates for this student.')
            ->modalWidth(Width::FiveExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal accounting-assessment-modal'])
            ->modalSubmitActionLabel('Record assessment')
            ->authorize(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $sources['categories'] !== [])
            ->fillForm(function () use ($categories, $sources): array {
                $units = (float) ($this->getRecord()->currentProposalVersion?->items->sum('units_snapshot') ?? 0);

                return [
                    'reviewed_proposal_id' => $this->getRecord()->current_proposal_version_id,
                    'reviewed_drop_id' => $sources['course_drop']?->id,
                    'category' => array_key_first($categories),
                    'tuition_rate_per_unit' => null,
                    'tuition_units' => $units > 0 ? FeePlanForm::formatUnitCount($units) : null,
                    'charges' => [['label' => '', 'amount' => null]],
                    'enrollment_payment' => ['amount' => null, 'due_at' => null],
                    'later_payments' => [],
                ];
            })
            ->schema([
                Hidden::make('reviewed_proposal_id')->required(),
                Hidden::make('reviewed_drop_id'),
                SchemaView::make('filament.admin.enrollments.accounting-correction-context')
                    ->viewData(fn (): array => $this->individualAssessmentModalContext())
                    ->columnSpanFull(),
                ...$categoryFields,
                SchemaSection::make('Charges')
                    ->description('Add each approved charge and its amount for this assessment.')
                    ->afterHeader([
                        Action::make('loadFromPublishedFeePlan')
                            ->label('Copy from standard fee plan')
                            ->icon('heroicon-o-arrow-down-on-square')
                            ->color('gray')
                            ->visible(fn (): bool => $this->applicablePublishedFeePlan() !== null)
                            ->action(function (Set $set): void {
                                $plan = $this->applicablePublishedFeePlan();
                                if ($plan === null) {
                                    return;
                                }

                                $charges = $plan->charges
                                    ->map(fn ($charge): array => [
                                        'label' => $charge->label,
                                        'amount' => (string) $charge->amount,
                                    ])
                                    ->values()
                                    ->all();

                                if ($charges !== []) {
                                    $set('charges', $charges);
                                }

                                $obligations = $plan->obligations->sortBy('sequence')->values();
                                if ($obligations->isNotEmpty()) {
                                    $first = $obligations->first();
                                    $set('enrollment_payment', [
                                        'amount' => (string) $first->amount,
                                        'due_at' => $first->due_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i:s'),
                                    ]);
                                    $set('later_payments', $obligations->slice(1)->values()->map(fn ($obligation): array => [
                                        'amount' => (string) $obligation->amount,
                                        'due_at' => $obligation->due_at?->timezone(config('app.display_timezone'))->format('Y-m-d H:i:s'),
                                    ])->all());
                                }
                            }),
                    ])
                    ->contained(false)
                    ->extraAttributes(['class' => 'accounting-finance-form-section'])
                    ->schema([
                        FeePlanForm::tuitionCalculatorSection(includeCode: false),
                        Repeater::make('charges')->label('Charges')->hiddenLabel()->addActionLabel('Add charge')->reorderable(false)
                            ->itemLabel(fn (array $state): string => filled($state['label'] ?? null) ? (string) $state['label'] : 'Charge')
                            ->deletable(fn (?array $state): bool => count($state ?? []) > 1)
                            ->schema([
                                TextInput::make('label')->label('Charge name')->required()->maxLength(255),
                                TextInput::make('amount')->label('Amount')->numeric()->minValue(0)->prefix('PHP')->required()->live(onBlur: true),
                            ])->minItems(1)->columns(['default' => 1, 'md' => 2]),
                    ])->columnSpanFull(),
                SchemaSection::make(fn (Get $get): string => $get('category') === Assessment::CategoryAuthorizedAdjustment ? 'Payment dates for this change' : 'Payment dates')
                    ->description('Set the amount and due date for each payment. Dates use '.config('app.display_timezone').'.')
                    ->afterHeader([
                        Action::make('matchEnrollmentPaymentToCharges')
                            ->label('Match enrollment payment to total charges')
                            ->icon('heroicon-o-calculator')
                            ->color('gray')
                            ->visible(fn (Get $get): bool => empty($get('later_payments')))
                            ->action(function (Get $get, Set $set): void {
                                $totals = $this->assessmentDraftTotals($get('charges') ?? [], $get('enrollment_payment') ?? [], []);
                                if ($totals['chargeTotal'] !== null) {
                                    $set('enrollment_payment.amount', number_format($totals['chargeTotal'] / 100, 2, '.', ''));
                                }
                            }),
                    ])
                    ->contained(false)
                    ->extraAttributes(['class' => 'accounting-finance-form-section'])
                    ->schema([
                        Group::make([
                            Text::make(fn (Get $get): string => $get('../category') === Assessment::CategoryAuthorizedAdjustment ? 'Additional payment for this change' : 'Enrollment payment')->weight('medium'),
                            TextInput::make('amount')->label('Amount')->numeric()->minValue(0)->prefix('PHP')->required()->live(onBlur: true),
                            DateTimePicker::make('due_at')->label('Due date and time')->timezone(config('app.display_timezone'))->helperText(config('app.display_timezone'))->required()->seconds(false),
                        ])->statePath('enrollment_payment')->columns(['default' => 1, 'md' => 3]),
                        TextInput::make('no_payment_required_authority_reference')->label('Approval for no enrollment payment')
                            ->helperText('Name the school authority allowing a zero enrollment payment.')
                            ->required(fn (Get $get): bool => filled($get('enrollment_payment.amount')) && (float) $get('enrollment_payment.amount') === 0.0)
                            ->visible(fn (Get $get): bool => filled($get('enrollment_payment.amount')) && (float) $get('enrollment_payment.amount') === 0.0)
                            ->maxLength(255),
                        Repeater::make('later_payments')->hiddenLabel()->addActionLabel('Add later payment')->defaultItems(0)->reorderable(false)
                            ->itemLabel('Later payment')
                            ->schema([
                                TextInput::make('amount')->label('Amount')->numeric()->minValue(0)->prefix('PHP')->required()->live(onBlur: true),
                                DateTimePicker::make('due_at')->label('Due date and time')->timezone(config('app.display_timezone'))->helperText(config('app.display_timezone'))->required()->seconds(false),
                            ])->columns(['default' => 1, 'md' => 2]),
                    ])->columnSpanFull(),
                SchemaSection::make('School approval')
                    ->description('Provide the school approval details for this assessment.')
                    ->contained(false)
                    ->extraAttributes(['class' => 'accounting-finance-form-section'])
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            TextInput::make('authority_reference')->label('Approval memo or reference')->placeholder('e.g., Memo 2026-014 or approving office')->required()->maxLength(255),
                            DatePicker::make('authority_date')->label('Approval date')->required(),
                        ]),
                    ])->columnSpanFull(),
                SchemaView::make('filament.admin.enrollments.accounting-draft-totals')
                    ->viewData(fn (Get $get): array => $this->assessmentDraftTotals($get('charges') ?? [], $get('enrollment_payment') ?? [], $get('later_payments') ?? []))
                    ->columnSpanFull(),
            ])
            ->action(function (array $data): void {
                try {
                    $charges = collect($data['charges'])->values()->map(fn (array $row, int $index): array => [
                        'code' => 'CHARGE-'.($index + 1), 'label' => $row['label'], 'amount' => (string) $row['amount'],
                    ])->all();
                    $payments = collect([$data['enrollment_payment'], ...array_values($data['later_payments'] ?? [])])
                        ->map(fn (array $row, int $index): array => [
                            'code' => $index === 0 ? 'ENROLLMENT' : 'PAYMENT-'.($index + 1),
                            'label' => $index === 0
                                ? ($data['category'] === Assessment::CategoryAuthorizedAdjustment ? 'Additional payment for this change' : 'Enrollment payment')
                                : 'Later payment '.($index),
                            'purpose' => $index === 0 ? 'Enrollment' : 'TermPayment',
                            'amount' => (string) $row['amount'], 'due_at' => $row['due_at'], 'required_for_enrollment' => $index === 0,
                        ])->all();
                    $drop = filled($data['reviewed_drop_id'] ?? null)
                        ? CourseDropRecord::query()->where('enrollment_id', $this->getRecord()->id)->findOrFail((int) $data['reviewed_drop_id']) : null;
                    app(RecordAuthorizedIndividualAssessment::class)->execute(
                        $this->getRecord(), $this->actor(), $data['category'], $data['authority_reference'],
                        CarbonImmutable::parse($data['authority_date'], config('app.timezone')),
                        $charges, $payments, $data['no_payment_required_authority_reference'] ?? null,
                        courseDrop: $drop, expectedProposalVersionId: (int) $data['reviewed_proposal_id'],
                    );
                    $this->record = $this->getRecord()->refresh();
                    $this->individualAssessmentContext = null;
                    Notification::make()->title('Assessment recorded')->success()->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Assessment was not recorded', $exception);
                }
            });
    }

    public function recordApprovedCoverageAction(): Action
    {
        $replacements = $this->replacementCoverageOptions();

        return Action::make('recordApprovedCoverage')
            ->label('Record approved coverage')
            ->icon('heroicon-o-shield-check')
            ->modalHeading('Record approved coverage')
            ->modalDescription('Record the approved scholarship, sponsorship or subsidy against a named fee.')
            ->modalSubmitActionLabel('Record coverage')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->isAccountingActor())
            ->visible(fn (): bool => $this->isAccountingActor()
                && ($this->coverageFeeOptions() !== [] || $replacements !== []))
            ->fillForm(fn (): array => [
                'reviewed_assessment_id' => $this->accountingViewData()['assessment']?->id,
                'mode' => $this->coverageFeeOptions() !== [] ? 'new' : 'replace',
            ])
            ->schema([
                Hidden::make('reviewed_assessment_id')->required(),
                SchemaView::make('filament.admin.enrollments.accounting-correction-context')
                    ->viewData(fn (): array => $this->correctionContext())
                    ->columnSpanFull(),
                ...($replacements !== [] ? [
                    Radio::make('mode')->label('Coverage entry')->options(fn (): array => [
                        ...($this->coverageFeeOptions() !== [] ? ['new' => 'New coverage'] : []), 'replace' => 'Replace existing coverage',
                    ])->required()->live()->afterStateUpdated(function (Set $set): void {
                        $set('obligation_id', null);
                        $set('supersedes_coverage_id', null);
                    }),
                ] : [Hidden::make('mode')->default('new')->required()]),
                Select::make('supersedes_coverage_id')->label('Approval to replace')->options($replacements)
                    ->visible(fn (Get $get): bool => $get('mode') === 'replace')
                    ->required(fn (Get $get): bool => $get('mode') === 'replace')->live()
                    ->afterStateUpdated(function (Set $set, mixed $state): void {
                        $previous = $this->replacementCoverages()->firstWhere('id', (int) $state);
                        $set('category', $previous?->category);
                        $set('safe_source_description', $previous?->safe_source_description);
                        $set('amount', $previous?->amount);
                    }),
                Text::make(function (Get $get): string {
                    $previous = $this->replacementCoverages()->firstWhere('id', (int) $get('supersedes_coverage_id'));

                    return $previous ? $previous->obligation?->label.' · Existing coverage '.$this->money($previous->amount).'. The replacement remains on this fee. Previous approval stays in account history.' : 'Choose the existing approval to replace.';
                })->visible(fn (Get $get): bool => $get('mode') === 'replace'),
                Select::make('obligation_id')->label('Fee to cover')->options(fn (): array => $this->coverageFeeOptions())
                    ->helperText('Only fees with available balance can receive new coverage.')
                    ->visible(fn (Get $get): bool => $get('mode') !== 'replace')
                    ->required(fn (Get $get): bool => $get('mode') !== 'replace'),
                Grid::make(['md' => 2])->schema([
                    Select::make('category')->label('Coverage type')->options(collect(ApprovedCoverage::Categories)->mapWithKeys(fn (string $category): array => [$category => AccountingDisplay::coverageCategoryLabel($category)])->all())->required(),
                    TextInput::make('amount')->label('Amount')->numeric()->minValue(0.01)->prefix('PHP')->required(),
                ]),
                TextInput::make('safe_source_description')->label('Funding source')->required()->maxLength(255),
                Grid::make(['md' => 2])->schema([
                    TextInput::make('authority_reference')->label('Approval memo or reference')->placeholder('e.g., Voucher 2026-014 or approving office')->required()->maxLength(255),
                    DatePicker::make('authority_date')->label('Approval date')->required(),
                ]),
                DatePicker::make('effective_date')->label('Effective from')->helperText('Coverage reduces the balance from this date.')->required(),
            ])
            ->action(function (array $data): void {
                abort_unless($this->isAccountingActor(), 403);
                try {
                    $account = $this->getRecord()->termAccount()->firstOrFail();
                    $assessment = $account->assessments()->where('state', Assessment::StateActive)->latest('version')->firstOrFail();
                    if ((int) $data['reviewed_assessment_id'] !== (int) $assessment->id) {
                        throw ValidationException::withMessages(['coverage' => 'The assessment changed. Close this form and review the current fees before recording coverage.']);
                    }
                    $previous = $data['mode'] === 'replace'
                        ? $this->replacementCoverages()->firstWhere('id', (int) $data['supersedes_coverage_id']) : null;
                    if ($data['mode'] === 'replace' && $previous === null) {
                        throw ValidationException::withMessages(['coverage' => 'That approval is no longer available to replace. Review the current coverage record.']);
                    }
                    $obligation = $assessment->obligations()->findOrFail($previous?->assessment_obligation_id ?? (int) $data['obligation_id']);
                    app(RecordApprovedCoverage::class)->execute(
                        $account,
                        $obligation,
                        [
                            'category' => $data['category'],
                            'safe_source_description' => $data['safe_source_description'],
                            'amount' => (string) $data['amount'],
                            'authority_reference' => $data['authority_reference'],
                            'authority_date' => $data['authority_date'],
                            'effective_date' => $data['effective_date'],
                            'supersedes_coverage_id' => $previous?->id,
                        ],
                        $this->actor(),
                    );
                    Notification::make()->title('Approved coverage recorded')->success()->send();
                    if ($this->isAccountingWorkspace()) {
                        $this->record = $this->getRecord()->refresh()->load('currentCorVersion');
                        $this->accountingContext = null;
                        $this->accountingMemo = [];
                        $this->dispatch('accounting-record-updated');
                        $this->resetPage('accountHistoryCoveragePage');
                    } else {
                        $this->redirect($this->enrollmentUrl());
                    }
                } catch (Throwable $exception) {
                    $this->failure('Coverage was not recorded', $exception);
                }
            });
    }

    public function verifyPaymentEvidenceAction(): Action
    {
        return Action::make('verifyPaymentEvidence')
            ->label('Verify payment')
            ->modalHeading('Record verified payment')
            ->modalDescription('Recording creates a verified payment and applies it to the targets below.')
            ->modalSubmitActionLabel('Record verified payment')
            ->modalCancelActionLabel('Keep under review')
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->icon('heroicon-o-check-circle')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->selectedPaymentProof() !== null)
            ->fillForm(fn (): array => ['evidence_id' => $this->selectedPaymentProof()?->id,
                'actual_verified_amount' => $this->selectedPaymentProof()?->claimed_amount])
            ->schema([
                $this->selectedProofField(),
                SchemaView::make('filament.admin.enrollments.payment-proof-context')->viewData(fn (): array => $this->paymentProofViewData()),
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('actual_verified_amount')->label('Verified amount')->numeric()->minValue(0.01)->prefix('PHP')->required()->live(onBlur: true)
                        ->helperText('Enter the amount confirmed by the independent source.'),
                    TextInput::make('or_number')->label('Official Receipt (OR) number (optional)')->maxLength(100)
                        ->helperText('Optional. Enter the Official Receipt number now, or map it later from payment history.'),
                ]),
                TextInput::make('external_check_reference')->label('Bank or collecting-office verification reference')->required()->maxLength(255)
                    ->helperText('Enter the bank transaction number, deposit reference, or collecting-office confirmation note used to verify this payment.'),
                SchemaView::make('filament.admin.enrollments.payment-proof-allocation')
                    ->viewData(fn (Get $get): array => $this->paymentProofViewData($get('actual_verified_amount'))),
            ])
            ->action(function (array $data): void {
                try {
                    DB::transaction(function () use ($data): void {
                        $payment = app(ReviewPaymentEvidence::class)->verify(
                            $this->boundSubmittedProof((int) $data['evidence_id']),
                            $this->actor(),
                            (string) $data['actual_verified_amount'],
                            $data['external_check_reference'],
                        );

                        if (filled($data['or_number'] ?? null)) {
                            app(MapOfficialReceiptToPayment::class)->execute(
                                $payment,
                                trim((string) $data['or_number']),
                                $this->actor(),
                            );
                        }
                    });
                    $this->record = $this->getRecord()->refresh()->load('currentCorVersion');
                    $this->sendPostPaymentAutoEnrollmentNotification('Payment verified', 'Payment receipt verified');
                    $this->redirect($this->enrollmentUrl(['view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Receipt was not verified', $exception);
                }
            });
    }

    public function recordOnsitePaymentAction(): Action
    {
        return Action::make('recordOnsitePayment')
            ->label('Record onsite payment')
            ->icon('heroicon-o-banknotes')
            ->modalHeading('Record onsite payment')
            ->modalDescription('Record a walk-in cash, e-wallet, or bank transfer payment collected by Accounting and apply it directly to this account.')
            ->modalSubmitActionLabel('Record payment')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->isAccountingActor())
            ->visible(fn (): bool => $this->canRecordOnsitePayment())
            ->fillForm(function (): array {
                $position = $this->accountingViewData()['position'] ?? null;
                $money = app(DecimalMoney::class);
                $currentDue = (string) ($position['current_due'] ?? '0');
                $remaining = (string) ($position['remaining_balance'] ?? '0');
                $defaultAmount = $money->toCents($currentDue) > 0 ? $currentDue : $remaining;

                return [
                    'channel' => 'cash',
                    'amount' => $money->toCents($defaultAmount) > 0 ? $defaultAmount : null,
                    'confirmed_at' => now(config('app.display_timezone')),
                ];
            })
            ->schema([
                Select::make('channel')
                    ->label('Payment channel')
                    ->options(Payment::manualConfirmationChannelOptions())
                    ->default('cash')
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set): mixed => $set('channel_account_id', null)),
                Select::make('channel_account_id')
                    ->label('School receiving account (optional)')
                    ->options(fn (Get $get): array => app(SchoolPaymentChannels::class)->accountOptionsForGroup((string) ($get('channel') ?? '')))
                    ->visible(fn (Get $get): bool => ($channel = (string) ($get('channel') ?? 'cash')) !== 'cash'
                        && app(SchoolPaymentChannels::class)->accountOptionsForGroup($channel) !== [])
                    ->placeholder('Choose the school account received on (optional)')
                    ->helperText('Optional. Captures the specific bank or e-wallet account in the payment record.')
                    ->live(),
                Grid::make(['md' => 2])->schema([
                    TextInput::make('amount')
                        ->label('Payment amount')
                        ->mask(RawJs::make('$money($input)'))
                        ->stripCharacters(',')
                        ->numeric()
                        ->minValue(0.01)
                        ->prefix('PHP')
                        ->extraInputAttributes(['inputmode' => 'decimal'])
                        ->helperText('Applies automatically to the oldest unpaid payment date first. Partial and advance payments are supported.')
                        ->required(),
                    TextInput::make('or_number')
                        ->label('Official Receipt (OR) number')
                        ->maxLength(100)
                        ->required(fn (Get $get): bool => $get('channel') === 'cash')
                        ->helperText(fn (Get $get): string => $get('channel') === 'cash'
                            ? 'Required for cash payments collected at the campus cashier.'
                            : 'Optional. Enter the Official Receipt (OR) number now or map it later from history.'),
                ]),
                Grid::make(['md' => 2])->schema([
                    TextInput::make('payment_reference')
                        ->label('Payment or transfer reference')
                        ->maxLength(120)
                        ->required(fn (Get $get): bool => $get('channel') !== 'cash')
                        ->helperText(function (Get $get): string {
                            $channel = (string) ($get('channel') ?? 'cash');
                            if ($channel === 'cash') {
                                return 'Optional for cash. Leave blank to generate a cashier reference automatically.';
                            }
                            $snapshot = app(SchoolPaymentChannels::class)->resolveAccountSnapshot(
                                $channel,
                                filled($get('channel_account_id')) ? (string) $get('channel_account_id') : null,
                            );

                            return SchoolPaymentChannels::referenceGuidance($channel, $snapshot['provider_or_bank'] ?? null)['hint'];
                        }),
                    DateTimePicker::make('confirmed_at')
                        ->label('Payment date and time')
                        ->timezone(config('app.display_timezone'))
                        ->helperText(config('app.display_timezone'))
                        ->maxDate(fn () => now(config('app.display_timezone'))),
                ]),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless($this->isAccountingActor(), 403);
                try {
                    $channel = (string) ($data['channel'] ?? 'cash');
                    $channelSnapshot = $channel !== 'cash'
                        ? app(SchoolPaymentChannels::class)->resolveAccountSnapshot(
                            $channel,
                            filled($data['channel_account_id'] ?? null) ? (string) $data['channel_account_id'] : null,
                        )
                        : null;
                    $reference = filled($data['payment_reference'] ?? null)
                        ? trim((string) $data['payment_reference'])
                        : ($channel === 'cash'
                            ? 'CASH-'.$this->getRecord()->id.'-'.strtoupper(Str::random(6))
                            : null);
                    $orNumber = filled($data['or_number'] ?? null)
                        ? trim((string) $data['or_number'])
                        : null;
                    $confirmedAt = filled($data['confirmed_at'] ?? null)
                        ? CarbonImmutable::parse((string) $data['confirmed_at'], config('app.display_timezone'))->utc()
                        : null;

                    app(PaymentConfirmationService::class)->confirmManualPayment(
                        enrollmentId: (int) $this->getRecord()->id,
                        amount: str_replace(',', '', trim((string) $data['amount'])),
                        channel: $channel,
                        paymentReference: $reference,
                        actor: $this->actor(),
                        confirmedAt: $confirmedAt,
                        orNumber: $orNumber,
                        channelSnapshot: $channelSnapshot,
                    );

                    $this->record = $this->getRecord()->refresh()->load('currentCorVersion');
                    $this->accountingContext = null;
                    $this->accountingMemo = [];
                    if ($this->isAccountingWorkspace()) {
                        $this->dispatch('accounting-record-updated');
                    }
                    $this->resetPage('accountHistoryPaymentPage');

                    $this->sendPostPaymentAutoEnrollmentNotification(
                        'Onsite payment recorded',
                        'Onsite payment recorded and applied to this account.',
                    );
                } catch (ValidationException $exception) {
                    throw $exception;
                } catch (RuntimeException $exception) {
                    $this->failure('Onsite payment was not recorded', $exception);
                    $action->halt();
                } catch (Throwable $exception) {
                    $this->failure('Onsite payment was not recorded', $exception);
                    $action->halt();
                }
            });
    }

    public function mapOfficialReceiptAction(): Action
    {
        return Action::make('mapOfficialReceipt')
            ->label('Map OR number')
            ->icon('heroicon-o-document-text')
            ->color('gray')
            ->size('sm')
            ->modalHeading('Map Official Receipt number')
            ->modalDescription('Assign the physical Official Receipt (OR) number to this verified payment.')
            ->modalSubmitActionLabel('Save OR number')
            ->modalWidth(Width::Medium)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->authorize(function (array $arguments): bool {
                $payment = $this->paymentForOfficialReceiptMapping($arguments);

                return $this->actor()->canAuthenticate()
                    && $this->isAccountingActor()
                    && $payment !== null
                    && Gate::allows('mapOfficialReceipt', $payment);
            })
            ->visible(function (array $arguments): bool {
                $payment = $this->paymentForOfficialReceiptMapping($arguments);

                return $this->isAccountingActor()
                    && $payment !== null
                    && Gate::allows('mapOfficialReceipt', $payment);
            })
            ->mountUsing(function (Schema $schema, array $arguments): void {
                $payment = $this->paymentForOfficialReceiptMapping($arguments);
                abort_unless($payment !== null && Gate::allows('mapOfficialReceipt', $payment), 403);
                $schema->fill(['payment_id' => $payment->id]);
            })
            ->schema([
                Hidden::make('payment_id')->required(),
                TextInput::make('or_number')
                    ->label('Official Receipt (OR) number')
                    ->required()
                    ->maxLength(100)
                    ->helperText('Enter the unique physical Official Receipt number issued for this payment.'),
            ])
            ->action(function (array $data, Action $action): void {
                abort_unless($this->isAccountingActor(), 403);
                $payment = $this->getRecord()->termAccount?->payments()->find((int) ($data['payment_id'] ?? 0));
                abort_unless($payment instanceof Payment && Gate::allows('mapOfficialReceipt', $payment), 403);

                try {
                    app(MapOfficialReceiptToPayment::class)->execute(
                        $payment,
                        trim((string) $data['or_number']),
                        $this->actor(),
                    );

                    $this->record = $this->getRecord()->refresh();
                    $this->accountingContext = null;
                    $this->accountingMemo = [];
                    if ($this->isAccountingWorkspace()) {
                        $this->dispatch('accounting-record-updated');
                    }

                    Notification::make()
                        ->title('Official Receipt mapped')
                        ->success()
                        ->send();
                } catch (ValidationException $exception) {
                    throw $exception;
                } catch (RuntimeException $exception) {
                    $this->failure('Official Receipt was not mapped', $exception);
                    $action->halt();
                } catch (Throwable $exception) {
                    $this->failure('Official Receipt was not mapped', $exception);
                    $action->halt();
                }
            });
    }

    private function canRecordOnsitePayment(): bool
    {
        if (! $this->isAccountingActor()) {
            return false;
        }
        $context = $this->accountingViewData();
        $position = $context['position'] ?? null;

        return $context['assessment'] !== null
            && $position !== null
            && ($position['state'] ?? 'Unavailable') !== 'Unavailable'
            && app(DecimalMoney::class)->toCents((string) ($position['remaining_balance'] ?? '0')) > 0;
    }

    /** @param array<string, mixed> $arguments */
    private function paymentForOfficialReceiptMapping(array $arguments): ?Payment
    {
        $paymentId = (int) ($arguments['payment'] ?? 0);
        if ($paymentId <= 0) {
            return null;
        }

        return $this->accountingViewData()['account']?->payments->firstWhere('id', $paymentId);
    }

    public function reverseApprovedCoverageAction(): Action
    {
        return Action::make('reverseApprovedCoverage')
            ->label('Reverse approved coverage')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->authorize(fn (): bool => $this->isAccountingActor())
            ->visible(fn (): bool => $this->isAccountingActor() && $this->appliedCoverages()->isNotEmpty())
            ->modalHeading('Reverse approved coverage')
            ->modalDescription('Corrects this term account. It does not refund money or contact a provider.')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Reverse coverage')->modalCancelActionLabel('Keep coverage')
            ->modalSubmitAction(fn (Action $action): Action|false => $this->reviewedCoverage !== [] ? $action : false)
            ->fillForm(function (): array {
                $this->reviewedCoverage = $this->appliedCoverages()->mapWithKeys(fn (ApprovedCoverage $coverage): array => [$coverage->id => $this->sourceFingerprint($coverage)])->all();

                return [];
            })
            ->schema([
                Select::make('coverage_id')->label('Coverage to reverse')->placeholder('Choose the coverage')->required()->live()
                    ->options(fn (): array => $this->coverageChoices())->visible(fn (): bool => count($this->reviewedCoverage) > 1),
                SchemaView::make('filament.admin.enrollments.accounting-correction-context')
                    ->viewData(fn (Get $get): array => $this->coverageContext($get('coverage_id'))),
                TextInput::make('authority_reference')->label('Approval memo or reference')->placeholder('e.g., Correction Memo 2026-019 or approving office')->required()->maxLength(255)
                    ->helperText('The approval for this reversal, not the original coverage reference.'),
                Textarea::make('safe_reason')->label('Reason')->required()->maxLength(1000)
                    ->helperText('Use an explanation that is safe for the student to read. Leave out private eligibility details.'),
                Text::make('The coverage becomes Reversed, the original record stays in history, and the account is recalculated. No refund or provider request is made, and enrollment is not revoked.')->color('neutral'),
            ])->action(function (array $data): void {
                try {
                    app(ReverseApprovedCoverage::class)->execute(
                        $this->reviewedCoverageSource($data['coverage_id'] ?? null),
                        $this->actor(), $data['authority_reference'], $data['safe_reason'],
                    );
                    Notification::make()->title('Approved coverage reversed')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Coverage was not reversed', $exception);
                }
            });
    }

    public function reversePaymentPostingAction(): Action
    {
        return Action::make('reversePaymentPosting')
            ->label('Record refund reversal')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->authorize(fn (): bool => $this->isAccountingActor())
            ->visible(fn (): bool => $this->isAccountingActor() && $this->reversiblePayments()->isNotEmpty())
            ->modalHeading('Record refund reversal')
            ->modalDescription('Record an externally completed refund against this verified payment. This action does not send money or contact a provider.')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Record refund reversal')->modalCancelActionLabel('Keep payment unchanged')
            ->modalSubmitAction(fn (Action $action): Action|false => $this->reviewedPayments !== [] ? $action : false)
            ->fillForm(function (): array {
                $this->reviewedPayments = $this->reversiblePayments()->mapWithKeys(fn (Payment $payment): array => [$payment->id => $this->sourceFingerprint($payment)])->all();

                return [];
            })
            ->schema([
                Select::make('payment_id')->label('Payment to correct')->placeholder('Choose the payment')->required()->live()
                    ->options(fn (): array => $this->paymentChoices())->visible(fn (): bool => count($this->reviewedPayments) > 1),
                SchemaView::make('filament.admin.enrollments.accounting-correction-context')
                    ->viewData(fn (Get $get): array => $this->paymentContext($get('payment_id'))),
                TextInput::make('authority_reference')->label('Approval memo or reference')->placeholder('e.g., Refund Voucher 2026-022 or approving office')->required()->maxLength(255),
                Textarea::make('safe_reason')->label('Reason')->required()->maxLength(1000)
                    ->helperText('Use a student-safe explanation of this correction.'),
                TextInput::make('external_refund_reference')->label('Completed external refund reference')->required()->maxLength(255),
                TextInput::make('refund_amount')->label('Actual refunded amount')->numeric()->minValue(0.01)->prefix('PHP')->required()
                    ->maxValue(fn (Get $get): float => $this->paymentRemaining($get('payment_id'))),
                DateTimePicker::make('refunded_at')->label('Completed refund date and time')->timezone(config('app.display_timezone'))->helperText(config('app.display_timezone'))->required()->maxDate(fn () => now(config('app.display_timezone'))),
                Text::make('This records a reversal against the original named allocations and recalculates the account. The original payment and official enrollment remain in history.')->color('neutral'),
            ])->action(function (array $data): void {
                try {
                    $payment = $this->reviewedPaymentSource($data['payment_id'] ?? null);
                    app(ReversePaymentPosting::class)->execute(
                        $payment,
                        $this->actor(),
                        $data['authority_reference'],
                        $data['safe_reason'],
                        $data['external_refund_reference'],
                        (string) $data['refund_amount'],
                        $data['refunded_at'],
                    );
                    Notification::make()->title('Refund reversal recorded')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Payment was not reversed', $exception);
                }
            });
    }

    /** @return Collection<int, ApprovedCoverage> */
    private function appliedCoverages(): Collection
    {
        return $this->getRecord()->termAccount?->coverages()
            ->where('state', ApprovedCoverage::StateApplied)
            ->with('obligation')
            ->orderBy('id')
            ->get() ?? new Collection;
    }

    /** @return Collection<int, Payment> */
    private function reversiblePayments(): Collection
    {
        return $this->getRecord()->termAccount?->payments()
            ->where('state', Payment::StatePosted)->where('evidence_status', 'verified')->whereNotNull('verified_at')
            ->with(['reversals', 'allocations.assessmentObligation'])
            ->orderByDesc('paid_at')
            ->get()
            ->filter(fn (Payment $payment): bool => $this->remainingReversible($payment) > 0)
            ->values() ?? new Collection;
    }

    private function remainingReversible(Payment $payment): float
    {
        return round((float) $payment->amount - (float) $payment->reversals->sum('amount'), 2);
    }

    /** Fingerprint of every stored fact of one source row, so unrelated account activity never invalidates a review. */
    private function sourceFingerprint(ApprovedCoverage|Payment $source): string
    {
        $facts = Arr::except($source->getAttributes(), ['created_at', 'updated_at']);
        if ($source instanceof Payment) {
            $facts['allocations'] = $source->allocations->map(fn ($allocation): array => [$allocation->assessment_obligation_id, $allocation->amount])->all();
            $facts['reversals'] = $source->reversals->map(fn (Payment $reversal): array => [$reversal->id, $reversal->amount])->all();
        }

        return hash('sha256', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    /** The reviewed ID: the only reviewed source, or the chosen one when several were offered. */
    private function reviewedSourceId(array $reviewed, mixed $chosen): ?int
    {
        $id = count($reviewed) === 1 ? array_key_first($reviewed) : (is_numeric($chosen) ? (int) $chosen : null);

        return array_key_exists((int) $id, $reviewed) ? (int) $id : null;
    }

    private function reviewedCoverageSource(mixed $chosen): ApprovedCoverage
    {
        $id = $this->reviewedSourceId($this->reviewedCoverage, $chosen);
        $coverage = $id === null ? null : $this->appliedCoverages()->firstWhere('id', $id);
        if (! $coverage instanceof ApprovedCoverage || ! hash_equals($this->reviewedCoverage[$id], $this->sourceFingerprint($coverage))) {
            throw ValidationException::withMessages(['coverage' => 'This coverage changed or is no longer active. Close this form and reopen it from the current account.']);
        }

        return $coverage;
    }

    private function reviewedPaymentSource(mixed $chosen): Payment
    {
        $id = $this->reviewedSourceId($this->reviewedPayments, $chosen);
        $payment = $id === null ? null : $this->reversiblePayments()->firstWhere('id', $id);
        if (! $payment instanceof Payment || ! hash_equals($this->reviewedPayments[$id], $this->sourceFingerprint($payment))) {
            throw ValidationException::withMessages(['payment' => 'This payment changed or has nothing left to correct. Close this form and reopen it from the current account.']);
        }

        return $payment;
    }

    /** @return array<int, string> */
    private function coverageChoices(): array
    {
        return $this->appliedCoverages()->filter(fn (ApprovedCoverage $coverage): bool => array_key_exists($coverage->id, $this->reviewedCoverage))
            ->mapWithKeys(fn (ApprovedCoverage $coverage): array => [
                $coverage->id => $this->money($coverage->amount).' · '.AccountingDisplay::coverageCategoryLabel($coverage->category).' · '.$coverage->obligation?->label,
            ])->all();
    }

    /** @return array<int, string> */
    private function paymentChoices(): array
    {
        return $this->reversiblePayments()->filter(fn (Payment $payment): bool => array_key_exists($payment->id, $this->reviewedPayments))
            ->mapWithKeys(fn (Payment $payment): array => [
                $payment->id => $this->money($this->remainingReversible($payment)).' left · '.$payment->channelLabel().' · '.AccountingDisplay::date($payment->paid_at),
            ])->all();
    }

    /** @return array{identity:string, lead:?string, facts:array<string,string>, unavailable:?string} */
    private function coverageContext(mixed $chosen): array
    {
        if ($this->reviewedCoverage === []) {
            return $this->correctionContext(unavailable: 'No active approved coverage can be reversed on this account. Close this form and review the account.');
        }
        $coverage = $this->appliedCoverages()->firstWhere('id', $this->reviewedSourceId($this->reviewedCoverage, $chosen));
        if (! $coverage instanceof ApprovedCoverage) {
            return $this->correctionContext(lead: 'Choose the coverage to reverse.');
        }

        return $this->correctionContext(array_filter([
            'Amount' => $this->money($coverage->amount).' · '.AccountingDisplay::coverageStateLabel($coverage->state),
            'Applies to' => $coverage->obligation?->label,
            'Category and source' => AccountingDisplay::coverageCategoryLabel($coverage->category).' · '.$coverage->safe_source_description,
            'Original approval' => collect([$coverage->authority_reference, AccountingDisplay::date($coverage->authority_date)])->filter()->implode(' · '),
        ]));
    }

    /** @return array{identity:string, lead:?string, facts:array<string,string>, unavailable:?string} */
    private function paymentContext(mixed $chosen): array
    {
        if ($this->reviewedPayments === []) {
            return $this->correctionContext(unavailable: 'No verified payment has an amount left to correct. Close this form and review the account.');
        }
        $payment = $this->reversiblePayments()->firstWhere('id', $this->reviewedSourceId($this->reviewedPayments, $chosen));
        if (! $payment instanceof Payment) {
            return $this->correctionContext(lead: 'Choose the verified payment to correct.');
        }

        return $this->correctionContext(array_filter([
            'Verified payment' => $this->money($payment->amount).' · '.$payment->channelLabel(),
            'Recorded reference' => $this->maskedProviderReference($payment->provider_reference) ?? $payment->external_check_reference,
            'Allocated to' => $payment->allocations->map(fn ($allocation): string => $allocation->assessmentObligation?->label.' · '.$this->money($allocation->amount))->implode('; '),
            'Already refunded' => (float) $payment->reversals->sum('amount') > 0 ? $this->money($payment->reversals->sum('amount')) : null,
            'Remaining available for correction' => $this->money($this->remainingReversible($payment)),
        ]));
    }

    private function paymentRemaining(mixed $chosen): float
    {
        $payment = $this->reversiblePayments()->firstWhere('id', $this->reviewedSourceId($this->reviewedPayments, $chosen));

        return $payment instanceof Payment ? $this->remainingReversible($payment) : 0.0;
    }

    public function exportTermAccountAction(): Action
    {
        return Action::make('exportTermAccount')
            ->label('Export verified payments (Excel CSV)')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Export verified payments (Excel CSV)')
            ->modalDescription('Review verified payments and reversals for this account.')
            ->modalIcon(null)
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['autofocus' => true, 'tabindex' => '-1'])->stickyModalHeader()->modalFooterActionsAlignment(Alignment::End)->stickyModalFooter()
            ->modalSubmitActionLabel(fn (): string => $this->financeExportFailed ? 'Try download again' : 'Download Excel CSV')
            ->modalCancelActionLabel('Close')
            ->fillForm(function (): array {
                $this->financeExportFailed = false;

                return ['state' => 'all'];
            })
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $this->getRecord()->termAccount !== null)
            ->schema([
                SchemaView::make('filament.admin.enrollments.finance-export-failure')
                    ->visible(fn (): bool => $this->financeExportFailed),
                SchemaView::make('filament.admin.enrollments.payment-export-context')
                    ->viewData(fn (): array => ['case' => $this->getRecord()]),
                Radio::make('state')->label('Entry type')
                    ->options(['all' => 'All entries', Payment::StatePosted => 'Verified payments', Payment::StateReversal => 'Reversals'])
                    ->default('all')->required()->inline()->live(),
                Grid::make(2)->schema([
                    DatePicker::make('from')->label('Verified from')->live(onBlur: true),
                    DatePicker::make('until')->label('Verified through')->live(onBlur: true)
                        ->rules(fn (Get $get): array => filled($get('from')) ? ['after_or_equal:'.$get('from')] : [])
                        ->validationMessages(['after_or_equal' => 'Choose a Verified through date on or after Verified from.']),
                ]),
                SchemaView::make('filament.admin.enrollments.verified-payment-export')
                    ->viewData(fn (Get $get): array => $this->verifiedPaymentExportData([
                        'state' => $get('state'), 'from' => $get('from'), 'until' => $get('until'),
                    ])),
            ])
            ->action(function (array $data, Action $action): void {
                try {
                    $account = $this->getRecord()->termAccount()->firstOrFail();
                    $export = app(CreateContextualFinanceExport::class)->createVerifiedPayments(
                        $this->actor(), $account, CreateContextualFinanceExport::VerifiedPaymentsPurpose,
                        $this->paymentExportFilters($data),
                    );
                    if ($export->outcome === FinanceExport::OutcomeNoRows) {
                        Notification::make()->title('No matching payment entries')
                            ->body('Adjust the entry type or verified dates. No CSV file was created.')->info()->send();

                        return;
                    }
                    $this->redirect(route('finance.exports.download', $export));
                } catch (Throwable $exception) {
                    if (! $exception instanceof ValidationException) {
                        report($exception);
                    }
                    $this->financeExportFailed = true;
                    $action->halt();
                }
            });
    }

    /** @param array<string, mixed> $data
     * @return array{state:?string,from:?string,until:?string}
     */
    private function paymentExportFilters(array $data): array
    {
        return [
            'state' => filled($data['state'] ?? null) && $data['state'] !== 'all' ? (string) $data['state'] : null,
            'from' => filled($data['from'] ?? null) ? (string) $data['from'] : null,
            'until' => filled($data['until'] ?? null) ? (string) $data['until'] : null,
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function verifiedPaymentExportData(array $data): array
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleAccounting), 403);
        try {
            return [
                ...app(CreateContextualFinanceExport::class)->verifiedPaymentsPreview(
                    $this->actor(), $this->getRecord()->termAccount()->firstOrFail(), $this->paymentExportFilters($data),
                ),
                'error' => null,
            ];
        } catch (ValidationException $exception) {
            return [
                'headers' => CreateContextualFinanceExport::VerifiedPaymentsHeaders, 'rows' => [], 'count' => null,
                'error' => collect($exception->errors())->flatten()->first(),
            ];
        }
    }

    private function downloadPaymentEvidenceAction(): Action
    {
        return Action::make('downloadPaymentEvidence')
            ->label('View submitted receipt')
            ->icon('heroicon-o-document-magnifying-glass')
            ->modalHeading('Submitted receipt')
            ->modalDescription(fn (): ?string => ($evidence = $this->latestSubmittedEvidence()) instanceof PaymentEvidenceVersion
                ? $evidence->original_name.' · '.str($evidence->state)->headline()
                : null)
            ->modalWidth(Width::FiveExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->latestSubmittedEvidence() instanceof PaymentEvidenceVersion)
            ->modalContent(function (): HtmlString {
                $evidence = $this->latestSubmittedEvidence();
                abort_unless($evidence instanceof PaymentEvidenceVersion, 404);

                return new HtmlString(Blade::render(
                    '<x-document-viewer :id="$id" :view-url="$viewUrl" :download-url="$downloadUrl" :title="$title" :download-label="$downloadLabel" :loading-message="$loadingMessage" :privacy-note="$privacyNote" :inline="true" :show-inline-download="true" />',
                    [
                        'id' => 'accounting-receipt-viewer-'.$evidence->id,
                        'viewUrl' => route('finance.payment-evidence.view', $evidence),
                        'downloadUrl' => route('finance.payment-evidence.download', $evidence),
                        'title' => 'Submitted receipt',
                        'downloadLabel' => 'Download receipt',
                        'loadingMessage' => 'Loading receipt…',
                        'privacyNote' => 'Private to the student and authorized school staff. If your browser cannot display the file, download it below.',
                    ],
                ));
            });
    }

    public function rejectPaymentEvidenceAction(): Action
    {
        return Action::make('rejectPaymentEvidence')
            ->label('Reject receipt')
            ->modalHeading('Reject submitted receipt')
            ->modalDescription('The receipt and reason stay in account history. The student can submit a corrected receipt.')
            ->modalSubmitActionLabel('Reject receipt')
            ->modalCancelActionLabel('Keep under review')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->selectedPaymentProof() !== null)
            ->fillForm(fn (): array => ['evidence_id' => $this->selectedPaymentProof()?->id])
            ->schema([
                $this->selectedProofField(),
                SchemaView::make('filament.admin.enrollments.payment-proof-context')->viewData(fn (): array => $this->paymentProofViewData()),
                Textarea::make('safe_reason')->label('Reason for the student')->helperText('Explain what could not be verified and what the student should correct.')->required()->maxLength(1000),
            ])
            ->action(function (array $data): void {
                try {
                    app(ReviewPaymentEvidence::class)->reject(
                        $this->boundSubmittedProof((int) $data['evidence_id']),
                        $this->actor(),
                        $data['safe_reason'],
                    );
                    Notification::make()->title('Payment receipt rejected')->success()->send();
                    $this->redirect($this->enrollmentUrl(['view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Receipt was not rejected', $exception);
                }
            });
    }

    public function resolveImpactReviewAction(): Action
    {
        return Action::make('resolveImpactReview')
            ->label('Record review outcome')
            ->icon('heroicon-o-clipboard-document-check')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->modalHeading('Record review outcome')->modalSubmitActionLabel('Record outcome')->modalCancelActionLabel('Keep under review')
            ->modalDescription('Record this source review only. It changes no academic result, registration or published timetable; any required correction is performed in its owning task.')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->fillForm(function (): array {
                $ids = array_keys($this->openImpactReviewOptions());
                $id = $ids[0] ?? null;

                return ['event_id' => $id, 'reviewed_source' => $this->impactFingerprint($id)];
            })
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->openImpactReviewOptions() !== [])
            ->schema([
                Select::make('event_id')->label('Source change to review')->options(fn (): array => $this->openImpactReviewOptions())->live()->required()->in(fn (): array => array_keys($this->openImpactReviewOptions()))
                    ->hidden(fn (): bool => count($this->openImpactReviewOptions()) === 1)->dehydratedWhenHidden()
                    ->afterStateUpdated(fn (Set $set, mixed $state) => $set('reviewed_source', $this->impactFingerprint($state ? (int) $state : null))),
                Hidden::make('reviewed_source')->required(),
                SchemaView::make('filament.admin.enrollments.review-source-context')->viewData(fn (Get $get): array => ['review' => $this->registrarReviewData($get('event_id') ? (int) $get('event_id') : null)]),
                Select::make('timetable_outcome')
                    ->label('What happened to the registration?')
                    ->options([
                        ResolveTimetableRevisionRegistrationImpact::OutcomeRetainedWithAcknowledgement => 'Keep the current class — acknowledgement required',
                        ResolveTimetableRevisionRegistrationImpact::OutcomeRegistrationChanged => 'Subjects changed',
                        ResolveTimetableRevisionRegistrationImpact::OutcomeCaseCancelled => 'Enrollment cancelled',
                    ])
                    ->live()
                    ->visible(fn (Get $get): bool => $this->isTimetableImpactEvent($get('event_id')))
                    ->required(fn (Get $get): bool => $this->isTimetableImpactEvent($get('event_id'))),
                TextInput::make('evidence_reference')
                    ->label('Acknowledgement evidence for these meeting changes')
                    ->helperText('Record the actual acknowledgement that covers the changed meeting facts.')
                    ->visible(fn (Get $get): bool => $this->isTimetableImpactEvent($get('event_id'))
                        && $get('timetable_outcome') === ResolveTimetableRevisionRegistrationImpact::OutcomeRetainedWithAcknowledgement)
                    ->required(fn (Get $get): bool => $this->isTimetableImpactEvent($get('event_id'))
                        && $get('timetable_outcome') === ResolveTimetableRevisionRegistrationImpact::OutcomeRetainedWithAcknowledgement)
                    ->dehydratedWhenHidden()
                    ->maxLength(255),
                Textarea::make('outcome')->label('Review outcome')->helperText('Describe the review and any correction already made.')
                    ->visible(fn (Get $get): bool => ! $this->isTimetableImpactEvent($get('event_id')))
                    ->required(fn (Get $get): bool => ! $this->isTimetableImpactEvent($get('event_id')))
                    ->maxLength(2000),
            ])
            ->action(function (array $data): void {
                try {
                    $eventId = (int) $data['event_id'];
                    if (! array_key_exists($eventId, $this->openImpactReviewOptions()) || ! hash_equals($this->impactFingerprint($eventId), (string) $data['reviewed_source'])) {
                        throw ValidationException::withMessages(['event_id' => 'The reviewed source or registration changed. Open the current review again.']);
                    }
                    $event = $this->getRecord()->registrationEvents()->findOrFail($eventId);
                    if ($event->event_type === 'TimetableRevisionImpactReviewOpened') {
                        $revisionId = (int) (string) str((string) $event->authority_reference)->after('timetable-revision:');
                        app(ResolveTimetableRevisionRegistrationImpact::class)->execute(
                            TimetableRevision::query()->findOrFail($revisionId),
                            $this->getRecord(),
                            $event,
                            $this->actor(),
                            (string) $data['timetable_outcome'],
                            $data['timetable_outcome'] === ResolveTimetableRevisionRegistrationImpact::OutcomeRetainedWithAcknowledgement
                                ? (string) ($data['evidence_reference'] ?? '') : '',
                        );
                    } else {
                        app(RecordRegistrationSourceImpactReview::class)->resolve(
                            $this->getRecord(),
                            $event,
                            $this->actor(),
                            (string) $data['outcome'],
                        );
                    }
                    Notification::make()->title('Impact review resolved')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Impact review was not resolved', $exception);
                }
            });
    }

    public function adjustRegistrationAction(): Action
    {
        return Action::make('adjustRegistration')->label('Apply change')->icon('heroicon-o-arrows-right-left')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->pendingAdjustment() !== null
                && $this->pendingAdjustment()->state === RegistrationProposalVersion::StateConfirmed
                && $this->adjustmentFinanceBases() !== [])
            ->modalHeading('Apply the confirmed change')->modalSubmitActionLabel('Apply change')->modalCancelActionLabel('Keep current registration')->modalWidth(Width::TwoExtraLarge)->stickyModalHeader()->stickyModalFooter()
            ->modalDescription('This applies the confirmed change and issues an updated certificate. Earlier registration and certificate records remain in history.')
            ->fillForm(function (): array {
                $options = $this->adjustmentLateAuthorities();
                $this->reviewedRegistrationLateAuthorities = $this->adjustmentWindowOpen()
                    ? []
                    : $this->registrationLateAuthoritySnapshot('adjustment', $this->adjustmentLateAuthorityContext(), $options);

                return [
                    'reviewed_proposal_id' => $this->pendingAdjustment()?->id,
                    'financial_basis' => count($this->adjustmentFinanceBases()) === 1 ? array_key_first($this->adjustmentFinanceBases()) : null,
                    'late_authority_id' => ! $this->adjustmentWindowOpen() && count($options) === 1 ? array_key_first($options) : null,
                ];
            })
            ->schema(function (): array {
                $options = $this->adjustmentLateAuthorities();

                return [
                    Hidden::make('reviewed_proposal_id')->required()->in([$this->pendingAdjustment()?->id]),
                    SchemaView::make('filament.admin.enrollments.adjustment-context')->viewData($this->adjustmentViewData()),
                    ...(count($this->adjustmentFinanceBases()) === 1 ? [Hidden::make('financial_basis')->required()->in(array_keys($this->adjustmentFinanceBases()))]
                        : [Select::make('financial_basis')->label('Recorded Accounting source')->options($this->adjustmentFinanceBases())->required()->in(array_keys($this->adjustmentFinanceBases()))]),
                    ...($this->adjustmentWindowOpen()
                        ? []
                        : match (count($options)) {
                            1 => [
                                Hidden::make('late_authority_id')->required()->in(array_keys($options)),
                                Text::make(fn (): string => $this->lateAuthoritySourceLabel(RegistrationLateAuthority::query()->findOrFail((int) array_key_first($options))))
                                    ->color('neutral')->weight('semibold'),
                            ],
                            0 => [
                                Text::make('No unused approval matches this confirmed change. Record the exact approval from this change before applying it.')->color('warning'),
                                Hidden::make('late_authority_id')->required(),
                            ],
                            default => [
                                Select::make('late_authority_id')->label('Exact late-adjustment approval')
                                    ->options($this->lateAuthoritySourceOptions($options))
                                    ->required()->in(array_keys($options)),
                            ],
                        }),
                ];
            })
            ->modalSubmitAction(fn (Action $action): Action|bool => ! $this->adjustmentWindowOpen() && $this->adjustmentLateAuthorities() === [] ? false : $action)
            ->extraModalFooterActions(fn (): array => ! $this->adjustmentWindowOpen() && $this->adjustmentLateAuthorities() === []
                ? [$this->recordLateAuthorityAction()->label('Record approval')->arguments(['action_type' => RegistrationLateAuthority::ActionAdjustment])]
                : [])
            ->action(function (array $data): void {
                try {
                    DB::transaction(function () use ($data): void {
                        $case = Enrollment::query()->lockForUpdate()->findOrFail($this->getRecord()->id);
                        $this->record = $case;
                        $proposal = $this->pendingAdjustment();
                        if ($proposal === null || $proposal->id !== (int) $data['reviewed_proposal_id'] || ! array_key_exists($data['financial_basis'], $this->adjustmentFinanceBases())) {
                            throw ValidationException::withMessages(['financial_basis' => 'The confirmed change or Accounting source changed. Review the current record.']);
                        }
                        [$kind, $id] = explode(':', $data['financial_basis']);
                        $lateAuthority = null;
                        if (! $this->adjustmentWindowOpen()) {
                            $options = $this->adjustmentLateAuthorities();
                            $lateAuthority = $this->assertReviewedRegistrationLateAuthority(
                                'adjustment',
                                $this->adjustmentLateAuthorityContext(),
                                $options,
                                $data['late_authority_id'] ?? null,
                            );
                        }
                        app(ApplyRegistrationAdjustment::class)->execute($case, $proposal, $this->actor(), $kind === 'confirmation' ? 'NoAdditionalCost' : 'Increase', $lateAuthority?->authority_reference ?? '',
                            $kind === 'assessment' ? Assessment::findOrFail($id) : null,
                            $lateAuthority,
                            $kind === 'confirmation' ? RegistrationAdjustmentFinanceConfirmation::findOrFail($id) : null);
                    });
                    Notification::make()->title('Official registration adjusted')->success()->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Registration was not adjusted', $exception);
                }
            });
    }

    /** @param array<int, string> $options @return array<int, string> */
    private function lateAuthoritySourceOptions(array $options): array
    {
        $ids = array_map('intval', array_keys($options));

        return RegistrationLateAuthority::query()
            ->where('enrollment_id', $this->getRecord()->id)
            ->whereKey($ids)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (RegistrationLateAuthority $authority): array => [
                $authority->id => $this->lateAuthoritySourceLabel($authority),
            ])
            ->all();
    }

    /** @param array<int, string> $options @param array<string, mixed> $context @return array<string, mixed> */
    private function registrationLateAuthoritySnapshot(string $workflow, array $context, array $options): array
    {
        $sources = [];
        if ($options !== []) {
            $ids = array_map('intval', array_keys($options));
            $authorities = RegistrationLateAuthority::query()
                ->where('enrollment_id', $context['case_id'])
                ->whereKey($ids)
                ->orderBy('id')
                ->get();

            foreach ($authorities as $authority) {
                $sources[(string) $authority->id] = $this->registrationLateAuthorityFingerprint($authority);
            }
        }

        ksort($sources);

        return ['workflow' => $workflow, 'context' => $context, 'sources' => $sources];
    }

    private function registrationLateAuthorityFingerprint(RegistrationLateAuthority $authority): string
    {
        return hash('sha256', json_encode([
            $authority->id,
            $authority->enrollment_id,
            $authority->term_id,
            $authority->action_type,
            $authority->before_course_enrollment_id,
            $authority->after_section_id,
            $authority->approving_office,
            $authority->authority_reference,
            $authority->authority_date?->toDateString(),
            $authority->reason,
            $authority->effective_at?->toIso8601String(),
            $authority->learner_acknowledgement_basis,
            $authority->source_academic_decision,
            $authority->consumed_at?->toIso8601String(),
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $context @param array<int, string> $options */
    private function assertReviewedRegistrationLateAuthority(string $workflow, array $context, array $options, mixed $sourceId): RegistrationLateAuthority
    {
        $reviewed = $this->reviewedRegistrationLateAuthorities;
        $id = is_numeric($sourceId) ? (string) (int) $sourceId : '';

        if ($id === ''
            || ($reviewed['workflow'] ?? null) !== $workflow
            || ($reviewed['context'] ?? null) !== $context
            || ! array_key_exists($id, $reviewed['sources'] ?? [])
            || ! array_key_exists((int) $id, $options)) {
            throw ValidationException::withMessages([
                'late_authority_id' => 'The exact late approval or reviewed subject changed. Reopen this action and review the current source.',
            ]);
        }

        $authority = RegistrationLateAuthority::query()
            ->where('enrollment_id', $context['case_id'])
            ->lockForUpdate()
            ->findOrFail((int) $id);

        if ($this->registrationLateAuthorityFingerprint($authority) !== $reviewed['sources'][$id]) {
            throw ValidationException::withMessages([
                'late_authority_id' => 'The exact late approval changed or was used. Reopen this action and review the current source.',
            ]);
        }

        return $authority;
    }

    /** @return array<string, mixed> */
    private function adjustmentLateAuthorityContext(): array
    {
        $proposal = $this->pendingAdjustment();
        $change = $this->adjustmentChange();

        return [
            'case_id' => (int) $this->getRecord()->id,
            'term_id' => (int) $this->getRecord()->term_id,
            'cor_id' => $this->getRecord()->current_cor_version_id,
            'proposal_id' => $proposal?->id,
            'proposal_hash' => $proposal?->content_hash,
            'confirmation_id' => $proposal?->confirmation?->id,
            'before_course_enrollment_id' => $change['before']?->id,
            'after_section_id' => $change['after']?->section_id,
        ];
    }

    /** @return array<string, mixed> */
    private function dropLateAuthorityContext(?int $courseId): array
    {
        return [
            'case_id' => (int) $this->getRecord()->id,
            'term_id' => (int) $this->getRecord()->term_id,
            'cor_id' => $this->getRecord()->current_cor_version_id,
            'course_enrollment_id' => $courseId,
        ];
    }

    /** @return array<int, array{reference:string,fingerprint:string}> */
    private function eligibleLateReopenAuthorities(): array
    {
        $events = $this->getRecord()->registrationEvents()->orderBy('id')->get();
        $outcome = $this->getRecord()->canonical_outcome;
        $eligible = [];

        foreach ($events->where('event_type', RecordLateEnrollmentReopenAuthority::EventType) as $authority) {
            $hasLaterOutcomeChange = $events->contains(fn (RegistrationCaseEvent $event): bool => $event->id > $authority->id
                && $event->from_outcome !== null
                && $event->to_outcome !== null
                && $event->from_outcome !== $event->to_outcome);
            $hasBeenUsed = $events->contains(fn (RegistrationCaseEvent $event): bool => $event->event_type === 'Reopened'
                && $event->id > $authority->id
                && $event->authority_reference === $authority->authority_reference);

            if ($authority->from_outcome !== $outcome
                || $authority->to_outcome !== $outcome
                || $hasLaterOutcomeChange
                || $hasBeenUsed
                || blank($authority->authority_reference)) {
                continue;
            }

            $fingerprint = hash('sha256', json_encode([
                $authority->id,
                $authority->enrollment_id,
                $authority->from_outcome,
                $authority->to_outcome,
                $authority->authority_reference,
                $authority->reason,
            ], JSON_THROW_ON_ERROR));
            $eligible[(int) $authority->id] = ['reference' => $authority->authority_reference, 'fingerprint' => $fingerprint];
        }

        krsort($eligible);

        return $eligible;
    }

    /** @return array<string, mixed> */
    private function lateReopenAuthoritySnapshot(): array
    {
        return [
            'context' => [
                'case_id' => (int) $this->getRecord()->id,
                'term_id' => (int) $this->getRecord()->term_id,
                'lock_version' => (int) $this->getRecord()->lock_version,
                'outcome' => $this->getRecord()->canonical_outcome,
            ],
            'sources' => $this->eligibleLateReopenAuthorities(),
        ];
    }

    private function assertReviewedLateReopenCase(): int
    {
        $reviewed = $this->reviewedLateReopenAuthorities['context'] ?? [];
        $current = Enrollment::query()->findOrFail($this->getRecord()->id);
        $currentContext = [
            'case_id' => (int) $current->id,
            'term_id' => (int) $current->term_id,
            'lock_version' => (int) $current->lock_version,
            'outcome' => $current->canonical_outcome,
        ];

        if ($reviewed === [] || $reviewed !== $currentContext) {
            throw ValidationException::withMessages([
                'case' => 'The Registration Case changed. Reopen this review before continuing.',
            ]);
        }

        $this->record = $current;

        return (int) $reviewed['lock_version'];
    }

    private function assertReviewedLateReopenAuthority(mixed $sourceId): RegistrationCaseEvent
    {
        $id = is_numeric($sourceId) ? (int) $sourceId : 0;
        $reviewed = $this->reviewedLateReopenAuthorities;
        $current = $this->lateReopenAuthoritySnapshot();
        $source = $reviewed['sources'][$id] ?? null;
        $this->assertReviewedLateReopenCase();

        if ($id < 1
            || ($reviewed['context'] ?? null) !== ($current['context'] ?? null)
            || ! is_array($source)) {
            throw ValidationException::withMessages([
                'late_authority_event_id' => 'The exact recorded approval or case changed. Reopen this review and select a current unused source.',
            ]);
        }

        $authority = $this->getRecord()->registrationEvents()
            ->where('event_type', RecordLateEnrollmentReopenAuthority::EventType)
            ->lockForUpdate()
            ->findOrFail($id);

        if ($source['fingerprint'] !== ($this->eligibleLateReopenAuthorities()[$id]['fingerprint'] ?? null)) {
            throw ValidationException::withMessages([
                'late_authority_event_id' => 'The selected approval changed or was used. Reopen this review and select a current unused source.',
            ]);
        }

        return $authority;
    }

    private function lateAuthoritySourceLabel(RegistrationLateAuthority $authority): string
    {
        return $authority->authority_reference.' · '.$authority->approving_office.' · '.$authority->authority_date?->format('j M Y');
    }

    private function lateReopenAuthoritySourceLabel(int $id): string
    {
        return $this->eligibleLateReopenAuthorities()[$id]['reference'] ?? '';
    }

    private function pendingAdjustment(): ?RegistrationProposalVersion
    {
        $case = $this->getRecord();
        $proposal = $case->currentProposalVersion;

        return $case->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled && $case->currentCorVersion !== null
            && $proposal?->purpose === RegistrationProposalVersion::PurposeAdjustment
            && in_array($proposal->state, [RegistrationProposalVersion::StateDraft, RegistrationProposalVersion::StateIssued, RegistrationProposalVersion::StateConfirmed], true)
            && $case->currentCorVersion->registration_proposal_version_id !== $proposal->id ? $proposal : null;
    }

    /** @return array<int,string> */
    private function adjustmentSectionOptions(string $type, ?int $beforeId = null): array
    {
        $current = $this->getRecord()->courseEnrollments()->where('is_current', true)->where('status', CourseEnrollment::StatusActive)->with('termOffering.curriculumEntry.courseSpecification')->get();
        $before = $current->firstWhere('id', $beforeId);
        $remaining = $type === 'Replace' ? $current->reject(fn (CourseEnrollment $course): bool => $course->id === $before?->id) : $current;
        $registeredCourseIds = $remaining->map(fn (CourseEnrollment $course): ?int => $course->termOffering?->curriculumEntry?->courseSpecification?->course_id);
        if ($type === 'Replace' && $before === null) {
            return [];
        }
        $descriptions = $this->sectionDescriptions();

        return $this->classChoices()['sections']->filter(fn (Section $section): bool => $section->id !== $before?->section_id
            && ! $registeredCourseIds->contains($section->termOffering?->curriculumEntry?->courseSpecification?->course_id))
            ->mapWithKeys(fn (Section $section): array => [$section->id => ($this->sectionOptions()[$section->id] ?? $section->code).' - '.($descriptions[$section->id] ?? '')])->all();
    }

    /** @return array{before:?CourseEnrollment,after:?RegistrationProposalItem,valid:bool} */
    private function adjustmentChange(): array
    {
        $current = $this->getRecord()->courseEnrollments()->where('is_current', true)->where('status', CourseEnrollment::StatusActive)->with('termOffering.curriculumEntry.courseSpecification')->get();
        $items = $this->pendingAdjustment()?->items()->with('termOffering.curriculumEntry.courseSpecification')->get() ?? collect();
        $removed = $current->whereNotIn('section_id', $items->pluck('section_id'))->values();
        $added = $items->whereNotIn('section_id', $current->pluck('section_id'))->values();

        return ['before' => $removed->first(), 'after' => $added->first(), 'valid' => $removed->count() <= 1 && $added->count() === 1];
    }

    /** @return array<string,string> */
    private function adjustmentFinanceBases(): array
    {
        $proposal = $this->pendingAdjustment();
        $change = $this->adjustmentChange();
        if ($proposal === null || ! $change['valid']) {
            return [];
        }
        $options = RegistrationAdjustmentFinanceConfirmation::query()->where('enrollment_id', $this->getRecord()->id)
            ->where('current_course_enrollment_id', $change['before']?->id)->where('replacement_section_id', $change['after']->section_id)
            ->where('financial_effect', RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost)->whereNull('consumed_at')->get()
            ->mapWithKeys(fn (RegistrationAdjustmentFinanceConfirmation $source): array => ['confirmation:'.$source->id => str_starts_with($source->authority_reference, 'accounting-no-cost:')
                ? 'No additional cost — Accounting confirmed'
                : 'No additional cost - '.$source->authority_reference])->all();
        $assessment = $this->getRecord()->termAccount?->assessments()->where('state', Assessment::StateActive)->latest('version')->first();
        if ($assessment !== null && $assessment->id !== $this->getRecord()->currentCorVersion?->assessment_id
            && $assessment->source_proposal_version_id === $proposal->id
            && app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($this->getRecord())['state'] === 'Cleared') {
            $options['assessment:'.$assessment->id] = 'Cleared updated assessment'.(filled($assessment->authority_reference) ? ' - '.$assessment->authority_reference : '');
        }

        return $options;
    }

    private function adjustmentWindowOpen(): bool
    {
        try {
            app(CalendarPhaseGateService::class)->assertEnrollmentAdjustmentWindowOpen($this->getRecord()->term_id);

            return true;
        } catch (CalendarGateViolation) {
            return false;
        }
    }

    /** @return array<int,string> */
    private function adjustmentLateAuthorities(): array
    {
        $change = $this->adjustmentChange();

        return RegistrationLateAuthority::query()->where('enrollment_id', $this->getRecord()->id)->where('term_id', $this->getRecord()->term_id)
            ->where('action_type', RegistrationLateAuthority::ActionAdjustment)->where('before_course_enrollment_id', $change['before']?->id)
            ->where('after_section_id', $change['after']?->section_id)->whereNull('consumed_at')
            ->where(fn (Builder $query): Builder => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->pluck('authority_reference', 'id')->all();
    }

    /** @return array<string,mixed> */
    public function adjustmentViewData(): array
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleRegistrar), 403);
        $case = $this->getRecord();
        $proposal = $this->pendingAdjustment();
        $change = $this->adjustmentChange();
        $bases = $this->adjustmentFinanceBases();
        $window = $this->adjustmentWindowOpen();
        $sourceCurrent = $proposal !== null && $proposal->published_timetable_version_id === PublishedTimetableVersion::where('term_id', $case->term_id)->where('state', PublishedTimetableVersion::StatePublished)->latest('version')->value('id');

        return ['case' => $case, 'proposal' => $proposal, 'change' => $change, 'financialBases' => $bases, 'windowOpen' => $window, 'sourceCurrent' => $sourceCurrent,
            'taskTitle' => ! $sourceCurrent || ! $change['valid'] ? 'Review the proposed change source' : match ($proposal?->state) {
                RegistrationProposalVersion::StateDraft => 'Review the proposed class change',
                RegistrationProposalVersion::StateIssued => 'Waiting for learner confirmation',
                default => $bases === [] ? 'Waiting for Accounting review' : (! $window && $this->adjustmentLateAuthorities() === [] ? 'Adjustment window needs authority' : 'Apply the confirmed class change'),
            },
            'officialSchedule' => app(RegistrationSchedulePresentation::class)->currentForEnrollment($case)];
    }

    public function prepareAdjustmentProposalAction(): Action
    {
        return Action::make('prepareAdjustmentProposal')->label('Add a subject')->icon('heroicon-o-document-plus')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled && $this->getRecord()->currentCorVersion !== null && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->modalHeading(fn (array $arguments): string => ($arguments['change_type'] ?? 'Add') === 'Replace' ? 'Change class' : 'Add a subject')
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Prepare one change for learner confirmation. Current official registration and the certificate stay unchanged. A revised selection needs a new confirmation.')
            ->modalSubmitActionLabel('Prepare change')->modalCancelActionLabel('Keep current registration')
            ->modalSubmitAction(function (Action $action): Action|bool {
                $reviewed = $this->reviewedAdjustmentRequest;
                if ($reviewed === null) {
                    return false;
                }

                $options = $this->adjustmentSectionOptions($reviewed['change_type'], $reviewed['current_course_enrollment_id']);
                $hasUnboundOpenProposal = $reviewed['pending_adjustment_id'] !== null
                    && $reviewed['revise_proposal_id'] !== $reviewed['pending_adjustment_id'];

                return $options === [] || $hasUnboundOpenProposal ? false : $action;
            })
            ->fillForm(function (array $arguments): array {
                $changeType = ($arguments['change_type'] ?? 'Add') === 'Replace' ? 'Replace' : 'Add';
                $currentCourseId = $changeType === 'Replace' && filled($arguments['current_course_enrollment_id'] ?? null)
                    ? (int) $arguments['current_course_enrollment_id'] : null;
                $pendingAdjustment = $this->pendingAdjustment();
                $reviseProposalId = filled($arguments['revise_proposal_id'] ?? null)
                    && (int) $arguments['revise_proposal_id'] === (int) ($pendingAdjustment?->id ?? 0)
                    ? $pendingAdjustment->id : null;
                $change = $this->adjustmentChange();
                $isSameReviewedChange = $currentCourseId !== null && (int) ($change['before']?->id ?? 0) === $currentCourseId;
                $this->reviewedAdjustmentRequest = [
                    'change_type' => $changeType,
                    'current_course_enrollment_id' => $currentCourseId,
                    'cor_id' => (int) $this->getRecord()->current_cor_version_id,
                    'lock_version' => (int) $this->getRecord()->lock_version,
                    'pending_adjustment_id' => $pendingAdjustment?->id,
                    'revise_proposal_id' => $reviseProposalId,
                ];

                return ['reviewed_lock_version' => $this->getRecord()->lock_version, 'current_course_enrollment_id' => $currentCourseId,
                    'reviewed_change_type' => $changeType,
                    'replacement_section_id' => $isSameReviewedChange ? $change['after']?->section_id : null];
            })
            ->schema([
                Hidden::make('reviewed_lock_version')->required(),
                Hidden::make('reviewed_change_type')->required(),
                Hidden::make('current_course_enrollment_id')->in(fn (): array => [null, ...array_keys($this->currentCourseOptions())]),
                Text::make(fn (Get $get): string => $get('current_course_enrollment_id') === null
                    ? 'Add one eligible subject to the current official registration.'
                    : 'Current official subject: '.($this->currentCourseOptions()[(int) $get('current_course_enrollment_id')] ?? 'Subject unavailable'))
                    ->visible(fn (Get $get): bool => $get('current_course_enrollment_id') !== null),
                Text::make('No eligible additional classes are available for this registration. Review the current offerings.')
                    ->visible(fn (Get $get): bool => $get('current_course_enrollment_id') === null && $this->adjustmentSectionOptions('Add') === [] && $this->pendingAdjustment() === null),
                Text::make('No eligible replacement classes are available for this subject. Review current offerings.')
                    ->visible(fn (Get $get): bool => filled($get('current_course_enrollment_id'))
                        && $this->adjustmentSectionOptions('Replace', (int) $get('current_course_enrollment_id')) === []),
                Text::make('A proposed change is already open. Review or revise that change before adding another subject.')
                    ->visible(fn (Get $get): bool => $get('current_course_enrollment_id') === null
                        && $this->pendingAdjustment() !== null
                        && ($this->reviewedAdjustmentRequest['revise_proposal_id'] ?? null) !== ($this->reviewedAdjustmentRequest['pending_adjustment_id'] ?? null)),
                Select::make('replacement_section_id')->label(fn (Get $get): string => $get('current_course_enrollment_id') === null ? 'New subject class' : 'Replacement class')
                    ->visible(fn (Get $get): bool => $get('current_course_enrollment_id') === null
                        ? ($this->pendingAdjustment() === null || ($this->reviewedAdjustmentRequest['revise_proposal_id'] ?? null) === ($this->reviewedAdjustmentRequest['pending_adjustment_id'] ?? null))
                            && $this->adjustmentSectionOptions('Add') !== []
                        : $this->adjustmentSectionOptions('Replace', (int) $get('current_course_enrollment_id')) !== [])
                    ->options(fn (Get $get): array => $this->adjustmentSectionOptions($get('current_course_enrollment_id') === null ? 'Add' : 'Replace', filled($get('current_course_enrollment_id')) ? (int) $get('current_course_enrollment_id') : null))
                    ->helperText('Use an eligible class on the current published timetable. Eligibility is checked again before the proposal is prepared.')
                    ->searchable()
                    ->required(fn (Get $get): bool => $get('current_course_enrollment_id') === null
                        ? ($this->pendingAdjustment() === null || ($this->reviewedAdjustmentRequest['revise_proposal_id'] ?? null) === ($this->reviewedAdjustmentRequest['pending_adjustment_id'] ?? null))
                            && $this->adjustmentSectionOptions('Add') !== []
                        : $this->adjustmentSectionOptions('Replace', (int) $get('current_course_enrollment_id')) !== [])
                    ->in(fn (Get $get): array => array_keys($this->adjustmentSectionOptions($get('current_course_enrollment_id') === null ? 'Add' : 'Replace', filled($get('current_course_enrollment_id')) ? (int) $get('current_course_enrollment_id') : null))),
            ])
            ->action(function (array $data, array $arguments): void {
                try {
                    $reviewed = $this->reviewedAdjustmentRequest;
                    $case = $this->getRecord()->fresh();
                    $this->record = $case;
                    if ($reviewed === null || ($arguments['change_type'] ?? 'Add') !== $reviewed['change_type']
                        || ($arguments['current_course_enrollment_id'] ?? null) !== $reviewed['current_course_enrollment_id']
                        || (filled($arguments['revise_proposal_id'] ?? null) ? (int) $arguments['revise_proposal_id'] : null) !== $reviewed['revise_proposal_id']
                        || ($data['reviewed_change_type'] ?? null) !== $reviewed['change_type']
                        || (filled($data['current_course_enrollment_id'] ?? null) ? (int) $data['current_course_enrollment_id'] : null) !== $reviewed['current_course_enrollment_id']
                        || (int) $data['reviewed_lock_version'] !== $reviewed['lock_version']
                        || (int) $case->lock_version !== $reviewed['lock_version']
                        || (int) $case->current_cor_version_id !== $reviewed['cor_id']
                        || (int) ($this->pendingAdjustment()?->id ?? 0) !== (int) ($reviewed['pending_adjustment_id'] ?? 0)
                        || ($reviewed['pending_adjustment_id'] !== null && $reviewed['revise_proposal_id'] !== $reviewed['pending_adjustment_id'])) {
                        throw ValidationException::withMessages(['replacement_section_id' => 'The reviewed official registration changed. Reopen the current registration before preparing this change.']);
                    }
                    $currentCourseId = $reviewed['current_course_enrollment_id'];
                    $changeType = $reviewed['change_type'];
                    if (! array_key_exists((int) $data['replacement_section_id'], $this->adjustmentSectionOptions($changeType, $currentCourseId))) {
                        throw ValidationException::withMessages(['replacement_section_id' => 'Select a current eligible class that changes this registration.']);
                    }
                    $currentCourses = CourseEnrollment::query()
                        ->where('enrollment_id', $this->getRecord()->id)
                        ->where('is_current', true)
                        ->where('status', CourseEnrollment::StatusActive)
                        ->get();
                    $sectionIds = $currentCourses->pluck('section_id');
                    if ($changeType === 'Replace') {
                        $before = $currentCourses->firstWhere('id', $currentCourseId);
                        if (! $before instanceof CourseEnrollment) {
                            throw ValidationException::withMessages([
                                'current_course_enrollment_id' => 'The selected official subject changed. Reopen the current registration before preparing a class change.',
                            ]);
                        }
                        $sectionIds = $sectionIds->reject(fn (mixed $id): bool => (int) $id === (int) $before->section_id);
                    }
                    $sectionIds->push((int) $data['replacement_section_id']);
                    app(PrepareRegistrationProposal::class)->execute(
                        $case,
                        $this->actor(),
                        $sectionIds->unique()->values()->all(),
                        $reviewed['lock_version'],
                        RegistrationProposalVersion::PurposeAdjustment,
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Proposed change prepared')->body('Send this selection for learner confirmation before applying the change.')->success()->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Adjustment proposal was not prepared', $exception);
                }
            });
    }

    public function confirmNoAdditionalCostAction(): Action
    {
        return Action::make('confirmNoAdditionalCost')
            ->label('Confirm no additional cost')
            ->icon('heroicon-o-check-circle')
            ->authorize(fn (): bool => $this->isAccountingActor())
            ->visible(fn (): bool => $this->isAccountingActor() && $this->noCostReviewContext() !== [])
            ->modalHeading('Confirm no additional cost')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-check-circle')
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Confirm that this learner-approved change requires no additional payment. Registrar applies the change separately.')
            ->modalSubmitActionLabel('Confirm no additional cost')->modalCancelActionLabel('Keep for review')
            ->fillForm(function (): array {
                $this->reviewedNoCostChange = $this->noCostReviewContext();

                return [];
            })
            ->schema([
                SchemaView::make('filament.admin.enrollments.no-cost-review-context')->viewData(fn (): array => ['context' => $this->reviewedNoCostChange]),
                Text::make('Your confirmation applies only to this reviewed change.')->color('neutral'),
            ])
            ->action(function (): void {
                try {
                    DB::transaction(function (): void {
                        $current = $this->noCostReviewContext(lockForUpdate: true);
                        if ($current === [] || $current !== $this->reviewedNoCostChange) {
                            throw ValidationException::withMessages(['change' => 'The reviewed registration change or confirmation changed. Open the current review again.']);
                        }
                        app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
                            $this->getRecord(),
                            $current['before_id'] !== null ? CourseEnrollment::query()->findOrFail($current['before_id']) : null,
                            Section::query()->findOrFail($current['after_id']),
                            $this->actor(),
                            null,
                            proposalVersionId: (int) $current['proposal_id'],
                        );
                    });
                    Notification::make()->title('No-additional-cost classification confirmed')->body('Registrar may now apply this exact registration change.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Accounting confirmation was not recorded', $exception);
                }
            });
    }

    /** @return array<string, mixed> */
    private function noCostReviewContext(bool $lockForUpdate = false): array
    {
        $case = Enrollment::query()->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->findOrFail($this->getRecord()->id);
        $this->record = $case;
        $proposal = $this->pendingAdjustment();
        if ($proposal === null || (int) $proposal->enrollment_id !== (int) $case->id || $proposal->state !== RegistrationProposalVersion::StateConfirmed) {
            return [];
        }
        $proposal = $case->proposalVersions()->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->findOrFail($proposal->id);
        $confirmation = $proposal->confirmation()->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->first();
        $cor = $case->corVersions()->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->find($case->current_cor_version_id);
        $timetable = PublishedTimetableVersion::query()->where('term_id', $case->term_id)->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version')->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->first();
        if ($proposal->state !== RegistrationProposalVersion::StateConfirmed || $confirmation === null
            || (int) $confirmation->learner_user_id !== (int) $case->credential_user_id || $confirmation->confirmed_at === null || $cor === null
            || $timetable === null || (int) $proposal->published_timetable_version_id !== (int) $timetable->id) {
            return [];
        }
        $courses = $case->courseEnrollments()->where('is_current', true)->where('status', CourseEnrollment::StatusActive)
            ->orderBy('id')->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->get();
        $items = $proposal->items()->orderBy('id')->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->get();
        $change = $this->adjustmentChange();
        $after = $change['after'];
        $before = $change['before'];
        $section = $after === null ? null : Section::query()->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->find($after->section_id);
        $offering = $section === null ? null : TermOffering::query()->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->find($section->term_offering_id);
        if (! $change['valid'] || $section === null || $section->state !== Section::StateOpen
            || (int) $section->term_offering_id !== (int) $after->term_offering_id
            || $offering === null || (int) $offering->term_id !== (int) $case->term_id
            || $items->pluck('section_id')->unique()->count() !== $items->count()) {
            return [];
        }

        return [
            'case_id' => $case->id, 'term_id' => $case->term_id, 'lock_version' => $case->lock_version,
            'proposal_id' => $proposal->id, 'cor_id' => $cor->id, 'before_id' => $before?->id, 'after_id' => $section->id,
            'fingerprint' => hash('sha256', json_encode([$proposal->getAttributes(), $confirmation->getAttributes(), $cor->getAttributes(), $timetable->getAttributes(), $courses->map->getAttributes()->all(), $items->map->getAttributes()->all(), $section->getAttributes(), $offering->getAttributes()], JSON_THROW_ON_ERROR)),
            'learner' => $case->credentialUser?->getFilamentName(), 'term' => $case->term?->label,
            'current_subject' => $before?->section?->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'New subject',
            'current_class' => $before?->section?->code, 'proposed_subject' => $after->course_title_snapshot,
            'proposed_class' => $section->code,
        ];
    }

    public function dropCourseAction(): Action
    {
        return Action::make('dropCourse')->label('Record Course Drop')->icon('heroicon-o-minus-circle')->color('danger')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled && $this->getRecord()->currentCorVersion !== null && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->modalHeading(fn (): string => $this->courseDropWindowOpen() ? 'Record the approved Course Drop' : 'Record a late Course Drop')
            ->modalDescription('Remove one selected subject from current registration and issue an updated certificate. Earlier registration remains in history. Accounting review follows; no refund is decided or recorded here.')
            ->modalSubmitActionLabel('Record Course Drop')->modalCancelActionLabel('Keep current registration')->modalWidth(Width::TwoExtraLarge)->stickyModalHeader()->stickyModalFooter()
            ->fillForm(function (array $arguments): array {
                $courseId = filled($arguments['course_enrollment_id'] ?? null) ? (int) $arguments['course_enrollment_id'] : null;
                $options = $this->dropLateAuthorities($courseId);
                $this->reviewedRegistrationLateAuthorities = $this->courseDropWindowOpen()
                    ? []
                    : $this->registrationLateAuthoritySnapshot('drop', $this->dropLateAuthorityContext($courseId), $options);

                return [
                    'reviewed_cor_id' => $this->getRecord()->current_cor_version_id,
                    'course_enrollment_id' => $courseId,
                    'reason' => filled($arguments['reason'] ?? null) ? (string) $arguments['reason'] : null,
                    'late_authority_id' => ! $this->courseDropWindowOpen() && count($options) === 1 ? array_key_first($options) : null,
                ];
            })
            ->schema(function (array $arguments): array {
                $rowCourseId = filled($arguments['course_enrollment_id'] ?? null) ? (int) $arguments['course_enrollment_id'] : null;

                return [
                    Hidden::make('reviewed_cor_id')->required()->in(fn (): array => [$this->getRecord()->current_cor_version_id]),
                    Hidden::make('course_enrollment_id')->required()->in(fn (): array => array_keys($this->currentCourseOptions()))
                        ->visible(fn (): bool => $rowCourseId !== null),
                    Text::make(fn (): string => 'Selected subject: '.($this->currentCourseOptions()[$rowCourseId] ?? 'Subject unavailable'))
                        ->visible(fn (): bool => $rowCourseId !== null),
                    Select::make('course_enrollment_id')->label('Current official subject')->options(fn (): array => $this->currentCourseOptions())
                        ->live()->required()->in(fn (): array => array_keys($this->currentCourseOptions()))
                        ->visible(fn (): bool => $rowCourseId === null)
                        ->afterStateUpdated(function (Set $set, mixed $state): void {
                            $courseId = filled($state) ? (int) $state : null;
                            $options = $this->courseDropWindowOpen() ? [] : $this->dropLateAuthorities($courseId);
                            $this->reviewedRegistrationLateAuthorities = $this->courseDropWindowOpen()
                                ? []
                                : $this->registrationLateAuthoritySnapshot('drop', $this->dropLateAuthorityContext($courseId), $options);
                            $set('late_authority_id', count($options) === 1 ? array_key_first($options) : null);
                        }),
                    SchemaView::make('filament.admin.enrollments.course-drop-context')->viewData(fn (Get $get): array => ['case' => $this->getRecord(), 'course' => $this->getRecord()->courseEnrollments()->with(['proposalItem', 'section'])->find($get('course_enrollment_id')), 'remaining' => count($this->currentCourseOptions()) - 1]),
                    Textarea::make('reason')->label('Reason for Course Drop')->required()->maxLength(2000),
                    Hidden::make('late_authority_id')->required()
                        ->in(fn (Get $get): array => array_keys($this->dropLateAuthorities(filled($get('course_enrollment_id')) ? (int) $get('course_enrollment_id') : null)))
                        ->visible(fn (Get $get): bool => ! $this->courseDropWindowOpen()
                            && count($this->dropLateAuthorities(filled($get('course_enrollment_id')) ? (int) $get('course_enrollment_id') : null)) <= 1),
                    Text::make(function (Get $get): string {
                        $sourceId = array_key_first($this->dropLateAuthorities(
                            filled($get('course_enrollment_id')) ? (int) $get('course_enrollment_id') : null,
                        ));
                        $authority = $sourceId === null ? null : RegistrationLateAuthority::query()->find($sourceId);

                        return $authority instanceof RegistrationLateAuthority ? $this->lateAuthoritySourceLabel($authority) : '';
                    })
                        ->color('neutral')->weight('semibold')
                        ->visible(fn (Get $get): bool => ! $this->courseDropWindowOpen()
                            && count($this->dropLateAuthorities(filled($get('course_enrollment_id')) ? (int) $get('course_enrollment_id') : null)) === 1),
                    Select::make('late_authority_id')->label('Exact late-drop approval')
                        ->options(fn (Get $get): array => $this->lateAuthoritySourceOptions(
                            $this->dropLateAuthorities(filled($get('course_enrollment_id')) ? (int) $get('course_enrollment_id') : null),
                        ))
                        ->required()
                        ->in(fn (Get $get): array => array_keys($this->dropLateAuthorities(filled($get('course_enrollment_id')) ? (int) $get('course_enrollment_id') : null)))
                        ->visible(fn (Get $get): bool => ! $this->courseDropWindowOpen()
                            && count($this->dropLateAuthorities(filled($get('course_enrollment_id')) ? (int) $get('course_enrollment_id') : null)) > 1),
                    Text::make(fn (Get $get): string => blank($get('course_enrollment_id'))
                        ? 'Select a subject to check its exact late-drop approval.'
                        : 'No unused approval matches this subject. Record the exact approval from this selected Course Drop before continuing.')
                        ->color('warning')
                        ->visible(fn (Get $get): bool => ! $this->courseDropWindowOpen()
                            && $this->dropLateAuthorities(filled($get('course_enrollment_id')) ? (int) $get('course_enrollment_id') : null) === []),
                ];
            })
            ->modalSubmitAction(function (Action $action): Action|bool {
                if ($this->courseDropWindowOpen()) {
                    return $action;
                }

                $courseId = filled($this->mountedActions[0]['data']['course_enrollment_id'] ?? null)
                    ? (int) $this->mountedActions[0]['data']['course_enrollment_id']
                    : null;

                return $courseId === null || $this->dropLateAuthorities($courseId) === [] ? false : $action;
            })
            ->extraModalFooterActions(function (Action $action): array {
                $courseId = filled($action->getRawData()['course_enrollment_id'] ?? null) ? (int) $action->getRawData()['course_enrollment_id'] : null;
                if ($this->courseDropWindowOpen() || $courseId === null || $this->dropLateAuthorities($courseId) !== []) {
                    return [];
                }

                return [$this->recordLateAuthorityAction()->label('Record late approval')
                    ->arguments(['action_type' => RegistrationLateAuthority::ActionCourseDrop, 'before_course_enrollment_id' => $courseId, 'reason' => $action->getRawData()['reason'] ?? null])];
            })
            ->action(function (array $data, array $arguments): void {
                try {
                    $rowCourseId = filled($arguments['course_enrollment_id'] ?? null) ? (int) $arguments['course_enrollment_id'] : null;
                    if ($rowCourseId !== null && (int) $data['course_enrollment_id'] !== $rowCourseId) {
                        throw ValidationException::withMessages(['course_enrollment_id' => 'The selected subject changed. Reopen its Course Drop action.']);
                    }
                    DB::transaction(function () use ($data): void {
                        $case = Enrollment::query()->lockForUpdate()->findOrFail($this->getRecord()->id);
                        if ($case->current_cor_version_id !== (int) $data['reviewed_cor_id']) {
                            throw ValidationException::withMessages(['reviewed_cor_id' => 'The official registration changed. Review the current subjects before recording a Course Drop.']);
                        }
                        $course = $case->courseEnrollments()->where('is_current', true)->where('status', CourseEnrollment::StatusActive)
                            ->findOrFail((int) $data['course_enrollment_id']);
                        $late = null;
                        if (! $this->courseDropWindowOpen()) {
                            $courseId = (int) $course->id;
                            $late = $this->assertReviewedRegistrationLateAuthority(
                                'drop',
                                $this->dropLateAuthorityContext($courseId),
                                $this->dropLateAuthorities($courseId),
                                $data['late_authority_id'] ?? null,
                            );
                        }
                        app(RecordCourseDrop::class)->execute($case, $course, $this->actor(), $data['reason'], $late?->authority_reference ?? '', $late);
                    });
                    Notification::make()->title('Course Drop recorded')->body('Accounting review is now required; earlier registration and certificate records remain in history.')->success()->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Course Drop was not recorded', $exception);
                }
            });
    }

    private function courseDropWindowOpen(): bool
    {
        try {
            app(CalendarPhaseGateService::class)->assertCourseDropWindowOpen($this->getRecord()->term_id);

            return true;
        } catch (CalendarGateViolation) {
            return false;
        }
    }

    /** @return array<int,string> */
    private function dropLateAuthorities(?int $courseId): array
    {
        return RegistrationLateAuthority::query()->where('enrollment_id', $this->getRecord()->id)->where('term_id', $this->getRecord()->term_id)
            ->where('action_type', RegistrationLateAuthority::ActionCourseDrop)->where('before_course_enrollment_id', $courseId)->whereNull('after_section_id')->whereNull('consumed_at')
            ->where(fn (Builder $query): Builder => $query->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->pluck('authority_reference', 'id')->all();
    }

    public function cancelCaseAction(): Action
    {
        return Action::make('cancelCase')
            ->label('Cancel enrollment')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Cancel enrollment?')
            ->modalAlignment(Alignment::Start)
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalDescription('Release the temporary class places and stop this enrollment. Existing payments and history remain recorded. Cancellation does not issue a refund.')
            ->modalContent(fn () => view('filament.admin.enrollments.recovery-context', ['case' => $this->getRecord()]))
            ->modalSubmitActionLabel('Cancel enrollment')
            ->modalCancelActionLabel('Keep enrollment')
            ->fillForm(fn (): array => ['reviewed_lock_version' => $this->getRecord()->lock_version])
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->schema([
                Hidden::make('reviewed_lock_version')->required(),
                Textarea::make('reason')->label('Reason for cancellation')->required()->maxLength(2000)->rows(3)
                    ->helperText('Your name and the time are recorded automatically.'),
            ])
            ->action(function (array $data): void {
                try {
                    $this->record = app(CancelRegistrationCase::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        $data['reason'],
                        (int) $data['reviewed_lock_version'],
                        null,
                    );
                    Notification::make()->title('Registration cancelled')->body('Reserved seats were released. Payments and history remain recorded.')->success()->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Registration Case was not cancelled', $exception);
                }
            });
    }

    public function recordLateAuthorityAction(): Action
    {
        return Action::make('recordLateAuthority')->label('Record late approval')->icon('heroicon-o-shield-check')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (array $arguments): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                && $this->getRecord()->currentCorVersion !== null
                && $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->lateActionContext($arguments) !== [])
            ->modalHeading('Record late approval')->modalSubmitActionLabel('Record approval')->modalCancelActionLabel('Keep current registration')->stickyModalHeader()->stickyModalFooter()
            ->modalWidth(Width::Large)->modalAlignment(Alignment::Start)
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalDescription('Record the school’s approval; apply the change separately.')
            ->fillForm(function (array $arguments): array {
                $context = $this->lateActionContext($arguments);
                $this->reviewedLateChange = $context === [] ? [] : $this->lateChangeReview($context);

                return [];
            })
            ->schema(function (array $arguments): array {
                $context = $this->lateActionContext($arguments);
                if ($context === []) {
                    return [];
                }
                $proposal = $context['action_type'] === RegistrationLateAuthority::ActionAdjustment ? $this->pendingAdjustment() : null;
                $confirmation = $proposal?->state === RegistrationProposalVersion::StateConfirmed ? $proposal->confirmation : null;

                return [
                    SchemaView::make('filament.admin.enrollments.late-change-context')->viewData([
                        'case' => $this->getRecord(), 'context' => $context,
                        'before' => $context['before_course_enrollment_id'] ? $this->getRecord()->courseEnrollments()->with('proposalItem', 'section')->find($context['before_course_enrollment_id']) : null,
                        'after' => $context['after_section_id'] ? Section::query()->find($context['after_section_id']) : null,
                        'afterTitle' => $proposal?->items->firstWhere('section_id', $context['after_section_id'])?->course_title_snapshot,
                        'confirmation' => $confirmation,
                    ]),
                    TextInput::make('approving_office')->label('Approving office')->required()->maxLength(120),
                    Grid::make(2)->schema([
                        TextInput::make('authority_reference')->label('Approval memo or reference')->placeholder('e.g., Memo 2026-014 or approving office')->required()->maxLength(255),
                        DatePicker::make('authority_date')->label('Approval date')->required()->maxDate(now()),
                    ]),
                    TextInput::make('learner_acknowledgement_basis')->label('Student acknowledgement')
                        ->visible($confirmation === null)->required($confirmation === null)->maxLength(255)
                        ->helperText('Reference to the student’s request for this change.'),
                    SchemaSection::make('Additional details (optional)')->collapsible()->collapsed()->schema([
                        Textarea::make('reason')->label('Additional note')->rows(2)->maxLength(2000),
                        TextInput::make('source_academic_decision')->label('Separate academic decision')->maxLength(255)
                            ->helperText('Only when a separate academic approval applies.'),
                    ]),
                ];
            })
            ->action(function (array $data, array $arguments): void {
                try {
                    DB::transaction(function () use ($data, $arguments): void {
                        $this->record = Enrollment::query()->whereKey($this->getRecord()->id)->lockForUpdate()->firstOrFail();
                        $context = $this->lateActionContext($arguments);
                        if ($context === [] || $this->reviewedLateChange === [] || $this->reviewedLateChange !== $this->lateChangeReview($context)) {
                            throw ValidationException::withMessages(['late_authority' => 'The reviewed registration or subjects changed. Reopen the approval form and review them again.']);
                        }
                        $confirmationId = $this->reviewedLateChange['confirmation_id'];
                        $acknowledgement = $confirmationId !== null
                            ? 'Student confirmation #'.$confirmationId.' for proposal #'.$this->reviewedLateChange['proposal_id']
                            : (string) ($data['learner_acknowledgement_basis'] ?? '');
                        app(RecordRegistrationLateAuthority::class)->execute(
                            $this->getRecord(),
                            $context['before_course_enrollment_id'] ? $this->getRecord()->courseEnrollments()->findOrFail($context['before_course_enrollment_id']) : null,
                            $context['after_section_id'] ? Section::query()->findOrFail($context['after_section_id']) : null,
                            $this->actor(), $context['action_type'],
                            (string) $data['approving_office'], (string) $data['authority_reference'],
                            CarbonImmutable::parse($data['authority_date']),
                            filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                            null, $acknowledgement,
                            filled($data['source_academic_decision'] ?? null) ? (string) $data['source_academic_decision'] : null,
                        );
                    });
                    Notification::make()->title('Late approval recorded')->body('Review the updated task and apply the change separately.')->success()->send();
                    if (($arguments['action_type'] ?? null) === RegistrationLateAuthority::ActionCourseDrop) {
                        $this->replaceMountedAction('dropCourse', [
                            'course_enrollment_id' => (int) $arguments['before_course_enrollment_id'],
                            'reason' => filled($arguments['reason'] ?? null) ? (string) $arguments['reason'] : null,
                        ]);
                    } else {
                        $this->replaceMountedAction('adjustRegistration');
                    }
                } catch (Throwable $exception) {
                    $this->failure('Late approval was not recorded', $exception);
                }
            });
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function lateChangeReview(array $context): array
    {
        $proposal = $context['action_type'] === RegistrationLateAuthority::ActionAdjustment ? $this->pendingAdjustment() : null;
        $confirmation = $proposal?->state === RegistrationProposalVersion::StateConfirmed ? $proposal->confirmation : null;

        return [...$context, 'cor_id' => $this->getRecord()->current_cor_version_id,
            'proposal_id' => $proposal?->id, 'proposal_hash' => $proposal?->content_hash,
            'confirmation_id' => $confirmation?->id];
    }

    /** @param array<string,mixed> $arguments @return array<string,mixed> */
    private function lateActionContext(array $arguments): array
    {
        if (($arguments['action_type'] ?? null) === RegistrationLateAuthority::ActionCourseDrop && filled($arguments['before_course_enrollment_id'] ?? null)) {
            $course = $this->getRecord()->courseEnrollments()->where('is_current', true)->where('status', CourseEnrollment::StatusActive)->findOrFail($arguments['before_course_enrollment_id']);

            return ['action_type' => RegistrationLateAuthority::ActionCourseDrop, 'before_course_enrollment_id' => $course->id, 'after_section_id' => null];
        }
        $change = $this->adjustmentChange();

        return $this->pendingAdjustment() !== null && $change['valid'] ? ['action_type' => RegistrationLateAuthority::ActionAdjustment, 'before_course_enrollment_id' => $change['before']?->id, 'after_section_id' => $change['after']->section_id] : [];
    }

    public function reopenCaseAction(): Action
    {
        return Action::make('reopenCase')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->label('Reopen enrollment')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('primary')
            ->modalHeading(fn (): string => $this->reopenRequiresLateAuthority() ? 'Reopen after the final cutoff' : 'Reopen enrollment')
            ->modalAlignment(Alignment::Start)
            ->modalWidth(Width::Large)
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalContent(fn () => view('filament.admin.enrollments.recovery-context', ['case' => $this->getRecord(), 'reopening' => true]))
            ->modalSubmitActionLabel('Reopen enrollment')
            ->modalCancelActionLabel('Keep current record')
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->visible(fn (): bool => in_array($this->getRecord()->canonical_outcome, Enrollment::reopenableOutcomes(), true)
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->disabled(fn (): bool => $this->reopenCutoff() === null)
            ->fillForm(function (array $arguments): array {
                $this->reviewedLateReopenAuthorities = $this->lateReopenAuthoritySnapshot();
                $options = $this->lateReopenAuthorityOptions();
                $requested = filled($arguments['late_authority_event_id'] ?? null) ? (int) $arguments['late_authority_event_id'] : null;
                $this->continuedLateReopenAuthorityId = $requested !== null && array_key_exists($requested, $options) ? $requested : null;
                $sourceId = $this->continuedLateReopenAuthorityId ?? (count($options) === 1 ? (int) array_key_first($options) : null);

                return ['reviewed_lock_version' => $this->getRecord()->lock_version, 'late_authority_event_id' => $sourceId];
            })
            ->schema(function (): array {
                $options = $this->lateReopenAuthorityOptions();
                $continuedId = $this->continuedLateReopenAuthorityId;

                return [
                    Hidden::make('reviewed_lock_version')->required(),
                    ...(! $this->reopenRequiresLateAuthority()
                        ? []
                        : ($continuedId !== null && array_key_exists($continuedId, $options)
                            ? [
                                Hidden::make('late_authority_event_id')->required()->in([$continuedId]),
                                Text::make('Recorded approval · '.$this->lateReopenAuthoritySourceLabel($continuedId))
                                    ->color('neutral')->weight('semibold'),
                            ]
                            : match (count($options)) {
                                1 => [
                                    Hidden::make('late_authority_event_id')->required()->in(array_keys($options)),
                                    Text::make('Recorded approval · '.$this->lateReopenAuthoritySourceLabel((int) array_key_first($options)))
                                        ->color('neutral')->weight('semibold'),
                                ],
                                0 => [
                                    Text::make('No unused approval matches this case and Term. Record the approval for this case before reopening.')->color('warning'),
                                    Hidden::make('late_authority_event_id')->required(),
                                ],
                                default => [
                                    Select::make('late_authority_event_id')->label('Recorded late-enrollment approval')
                                        ->options($options)->required()->in(array_keys($options)),
                                ],
                            })),
                    Textarea::make('reason')->label('Reason for reopening')->required()->maxLength(2000)->rows(3)
                        ->helperText('Explain why enrollment should resume. Your name and the time are recorded automatically.'),
                ];
            })
            ->modalSubmitAction(fn (Action $action): Action|bool => $this->reopenRequiresLateAuthority() && $this->lateReopenAuthorityOptions() === [] ? false : $action)
            ->extraModalFooterActions(fn (): array => $this->reopenRequiresLateAuthority() && $this->lateReopenAuthorityOptions() === []
                ? [$this->recordLateReopenAuthorityAction()->label('Record late-enrollment approval')]
                : [])
            ->modalDescription('Resume this enrollment using current subjects and eligibility.')
            ->action(function (array $data): void {
                try {
                    $reviewedLockVersion = $this->assertReviewedLateReopenCase();
                    $lateAuthority = null;
                    if ($this->reopenRequiresLateAuthority()) {
                        $lateAuthority = $this->assertReviewedLateReopenAuthority(
                            $data['late_authority_event_id'] ?? null,
                        );
                    }
                    $this->record = app(ReopenRegistrationCase::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        $data['reason'],
                        $lateAuthority?->authority_reference,
                        $reviewedLockVersion,
                        $lateAuthority,
                    );
                    Notification::make()->title('Registration reopened')->body('Prepare current subjects and recheck enrollment requirements.')->success()->send();
                    $this->redirect($this->enrollmentUrl());
                } catch (Throwable $exception) {
                    $this->failure('Registration Case was not reopened', $exception);
                }
            });
    }

    public function recordLateReopenAuthorityAction(): Action
    {
        return Action::make('recordLateReopenAuthority')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->label('Record late-enrollment approval')
            ->icon('heroicon-o-shield-check')
            ->modalHeading('Record late-enrollment approval')
            ->modalAlignment(Alignment::Start)
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalContent(fn () => view('filament.admin.enrollments.recovery-context', ['case' => $this->getRecord()]))
            ->modalSubmitActionLabel('Record and continue')
            ->modalCancelActionLabel('Keep current record')
            ->fillForm(function (): array {
                $this->reviewedLateReopenAuthorities = $this->lateReopenAuthoritySnapshot();

                return ['reviewed_lock_version' => $this->getRecord()->lock_version];
            })
            ->visible(fn (): bool => in_array($this->getRecord()->canonical_outcome, Enrollment::reopenableOutcomes(), true)
                && $this->actor()->can('confirmPlacement', $this->getRecord()) && $this->reopenRequiresLateAuthority())
            ->schema([
                Hidden::make('reviewed_lock_version')->required(),
                TextInput::make('authority_reference')->label('Approval memo or reference')->placeholder('e.g., Memo 2026-014 or approving office')->required()->maxLength(255),
            ])
            ->modalDescription('Record the actual approval received for this student, case and Term. This does not reopen enrollment.')
            ->action(function (array $data): void {
                try {
                    $reviewedLockVersion = $this->assertReviewedLateReopenCase();
                    $authority = app(RecordLateEnrollmentReopenAuthority::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        (string) $data['authority_reference'],
                        null,
                        $reviewedLockVersion,
                    );
                    Notification::make()->title('Late-enrollment approval recorded')->success()->send();
                    $this->replaceMountedAction('reopenCase', ['late_authority_event_id' => $authority->id]);
                } catch (Throwable $exception) {
                    $this->failure('Late-enrollment approval was not recorded', $exception);
                }
            });
    }

    public function reopenCutoff(): ?CarbonImmutable
    {
        try {
            return app(CalendarPhaseGateService::class)->finalEnrollmentCutoff((int) $this->getRecord()->term_id);
        } catch (CalendarGateViolation) {
            return null;
        }
    }

    public function reopenRequiresLateAuthority(): bool
    {
        $cutoff = $this->reopenCutoff();

        return $cutoff === null || now()->isAfter($cutoff);
    }

    public function lateReopenAuthorityOptions(): array
    {
        return collect($this->eligibleLateReopenAuthorities())
            ->mapWithKeys(fn (array $source, int $id): array => [$id => $source['reference']])
            ->all();
    }

    /**
     * Year level or curriculum term of each offered class, for the Choose subjects filters.
     *
     * @return array<string, string>
     */
    private function subjectPositionFilterOptions(string $part): array
    {
        return $this->classChoices()['sections']
            ->map(fn (Section $section): ?string => $this->subjectPosition($section)[$part])
            ->filter()
            ->unique()
            ->sort(SORT_NATURAL)
            ->mapWithKeys(fn (string $value): array => [$value => $part === 'year' && is_numeric($value) ? 'Year '.$value : $value])
            ->all();
    }

    /** @return array{year: ?string, term: ?string} */
    private function subjectPosition(Section $section): array
    {
        $entry = $section->termOffering?->curriculumEntry;

        return ['year' => filled($entry?->year_level) ? (string) $entry->year_level : null, 'term' => filled($entry?->term_label) ? (string) $entry->term_label : null];
    }

    /** Opens on the curriculum term matching the enrollment term (e.g. First Semester), when one is offered. */
    private function defaultSubjectTermFilter(): ?string
    {
        $termLabel = (string) $this->getRecord()->term?->label;

        return collect(array_keys($this->subjectPositionFilterOptions('term')))
            ->first(fn (string $curriculumTerm): bool => $termLabel !== '' && str_contains(strtolower($termLabel), strtolower($curriculumTerm)));
    }

    /**
     * Offered classes narrowed by the year and term filters. Classes already ticked stay listed so a filter never hides a choice.
     *
     * @param  list<int|string>  $selectedIds
     * @return array<int, string>
     */
    private function filteredSectionOptions(?string $year, ?string $term, array $selectedIds): array
    {
        $options = $this->sectionOptions($selectedIds);
        if (blank($year) && blank($term)) {
            return $options;
        }

        $selected = array_map('intval', $selectedIds);
        $visibleIds = $this->classChoices()['sections']
            ->filter(function (Section $section) use ($year, $term, $selected): bool {
                $position = $this->subjectPosition($section);

                return in_array((int) $section->id, $selected, true)
                    || ((blank($year) || $position['year'] === $year) && (blank($term) || $position['term'] === $term));
            })
            ->pluck('id')
            ->all();

        return array_intersect_key($options, array_flip($visibleIds));
    }

    /**
     * @param  array<int, int|string>|null  $selectedSectionIds
     * @return array<int, string>
     */
    private function sectionOptions(?array $selectedSectionIds = null): array
    {
        return app(RegistrationClassChoices::class)->sectionOptions($this->getRecord(), $selectedSectionIds, $this->classChoices());
    }

    /**
     * @return array{
     *     sections: Collection<int, Section>,
     *     meetings: Collection,
     *     reserved: Collection<int, int>,
     *     enrolled: Collection<int, int>,
     *     satisfiedCourseIds: Collection<int, int>
     * }
     */
    private function classChoices(): array
    {
        return $this->classChoiceContext ??= app(RegistrationClassChoices::class)->forCase($this->getRecord());
    }

    /** @return array<int, string> */
    private function sectionDescriptions(): array
    {
        return app(RegistrationClassChoices::class)->sectionDescriptions($this->getRecord(), $this->classChoices());
    }

    /** @param list<int|string> $sectionIds */
    private function selectedSubjectSummary(array $sectionIds): string
    {
        $sections = $this->classChoices()['sections']->whereIn('id', $sectionIds);
        $units = $sections->sum(fn (Section $section): float => (float) $section->termOffering?->courseSpecification()?->credit_units);

        return $sections->count().' '.str('subject')->plural($sections->count()).' · '.$units.' units';
    }

    /** @param list<int|string> $sectionIds */
    private function needsCurriculumPosition(array $sectionIds): bool
    {
        if ($this->getRecord()->selection_basis !== Enrollment::SelectionIndividuallyAdvised) {
            return false;
        }

        $selectedSections = $this->classChoices()['sections']->whereIn('id', $sectionIds)->values();
        if ($selectedSections->count() <= 1) {
            return false;
        }

        $curriculum = $this->resolveTargetCurriculumForSections($selectedSections);
        $curriculumEntries = $curriculum instanceof CurriculumVersion
            ? CurriculumEntry::query()
                ->with('courseSpecification.requirements')
                ->where('curriculum_version_id', $curriculum->id)
                ->orderBy('sequence')
                ->orderBy('id')
                ->get()
            : collect();
        $eligibility = app(RegistrationAcademicEligibilityQuery::class);

        return $selectedSections
            ->map(function (Section $section) use ($curriculumEntries, $eligibility): string {
                $matched = $curriculumEntries->isNotEmpty()
                    ? $eligibility->resolveCurriculumEntryFromEntries($curriculumEntries, $section->termOffering, $section)
                    : null;
                $entry = $matched ?? $section->termOffering?->curriculumEntry;

                return collect([
                    $entry?->year_level,
                    $entry?->term_label,
                    $entry?->term_type,
                ])->implode('|');
            })
            ->unique()
            ->count() > 1;
    }

    /** @param list<int|string> $sectionIds
     * @return array<string, string>
     */
    private function curriculumPositionOptions(array $sectionIds): array
    {
        $selectedSections = $this->classChoices()['sections']->whereIn('id', $sectionIds)->values();
        $curriculum = $this->resolveTargetCurriculumForSections($selectedSections);

        if ($curriculum instanceof CurriculumVersion) {
            return app(StudentUnitLoadService::class)->positionOptions($this->getRecord(), (int) $curriculum->id);
        }

        $curriculumIds = $selectedSections
            ->pluck('termOffering.curriculumEntry.curriculum_version_id')
            ->filter()
            ->unique();

        return $curriculumIds->count() === 1
            ? app(StudentUnitLoadService::class)->positionOptions($this->getRecord(), (int) $curriculumIds->first())
            : [];
    }

    /**
     * @param  Collection<int, Section>  $selectedSections
     */
    private function resolveTargetCurriculumForSections(Collection $selectedSections): ?CurriculumVersion
    {
        $case = $this->getRecord();
        $case->loadMissing(['studentProfile', 'admissionApplication', 'term']);
        $programId = $case->studentProfile?->program_id ?? $case->admissionApplication?->program_id;
        $homeCurriculumIds = $selectedSections
            ->map(fn (Section $section): ?CurriculumEntry => $section->termOffering?->curriculumEntry)
            ->filter(fn (?CurriculumEntry $entry): bool => $entry instanceof CurriculumEntry
                && ($programId === null || (int) $entry->curriculumVersion?->program_id === (int) $programId))
            ->pluck('curriculum_version_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $fallbackCurriculumId = $homeCurriculumIds->count() === 1 ? $homeCurriculumIds->first() : null;

        return app(RegistrationAcademicEligibilityQuery::class)->resolveStudentCurriculum($case, $fallbackCurriculumId);
    }

    /** @return array<int, string> */
    private function currentCourseOptions(): array
    {
        return $this->getRecord()->courseEnrollments()
            ->with('termOffering.curriculumEntry.courseSpecification.course')
            ->where('is_current', true)
            ->where('status', CourseEnrollment::StatusActive)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (CourseEnrollment $course): array => [
                $course->id => collect([
                    $course->termOffering?->course()?->code,
                    $course->section?->code,
                ])->filter()->implode(' — '),
            ])->all();
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    private function needsOverloadAuthority(): bool
    {
        $case = $this->getRecord();
        $proposal = $case->currentProposalVersion;
        if ($case->studentProfile?->academic_standing !== StudentProfile::StandingGraduationCandidate
            || $proposal?->state !== RegistrationProposalVersion::StateDraft
            || data_get($proposal->source_snapshot, 'unit_load.requires_graduating_overload') !== true) {
            return false;
        }
        try {
            app(StudentUnitLoadService::class)->assertProposalPermitted($case, $proposal);

            return false;
        } catch (ValidationException $exception) {
            return str_contains($exception->getMessage(), 'graduating-overload authority');
        }
    }

    private function overloadFingerprint(): string
    {
        $case = $this->getRecord()->fresh();
        $proposal = $case->currentProposalVersion;
        if ($proposal === null) {
            return '';
        }

        return hash('sha256', json_encode([$proposal->id, $proposal->content_hash, $proposal->state, app(StudentUnitLoadService::class)->currentProposalLoad($case, $proposal)], JSON_THROW_ON_ERROR));
    }

    private function impactFingerprint(?int $eventId): string
    {
        $case = $this->getRecord()->fresh();
        $event = $case->registrationEvents()->find($eventId);
        if ($event === null) {
            return '';
        }
        $revision = $event->event_type === 'TimetableRevisionImpactReviewOpened' ? TimetableRevision::find((int) str($event->authority_reference)->after('timetable-revision:')->toString()) : null;

        return hash('sha256', json_encode([$event->getAttributes(), $case->current_proposal_version_id, $case->current_cor_version_id, $case->canonical_outcome, $revision?->getAttributes()], JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    public function registrarReviewData(?int $eventId = null): array
    {
        abort_unless($this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar), 403);
        $case = $this->getRecord()->fresh();
        $proposal = $case->currentProposalVersion;
        $options = $this->openImpactReviewOptions();
        $eventId ??= array_key_first($options);
        $event = $eventId !== null && array_key_exists($eventId, $options) ? $case->registrationEvents()->with('actor')->find($eventId) : null;
        $revision = $event?->event_type === 'TimetableRevisionImpactReviewOpened' ? TimetableRevision::with('sourceVersion.meetings')->find((int) str($event->authority_reference)->after('timetable-revision:')->toString()) : null;
        $load = [];
        if ($event === null && $proposal !== null) {
            try {
                $load = app(StudentUnitLoadService::class)->currentProposalLoad($case, $proposal);
            } catch (ValidationException) {
                $load = [];
            }
        }

        return ['case' => $case, 'proposal' => $proposal, 'event' => $event, 'revision' => $revision, 'load' => $load, 'kind' => $event === null ? 'overload' : ($revision !== null ? 'timetable' : 'academic'), 'options' => $options];
    }

    private function proposalUnitLoadSummary(): string
    {
        $snapshot = data_get($this->getRecord()->currentProposalVersion?->source_snapshot, 'unit_load', []);
        $normal = data_get($snapshot, 'normal_total', 'Unavailable');
        $requested = data_get($snapshot, 'requested_total', 'Unavailable');
        $position = collect([
            data_get($snapshot, 'year_level'),
            data_get($snapshot, 'term_label'),
        ])->filter()->implode(' — ');

        return "Applicable curriculum term: {$position}. Normal total: {$normal} units. Requested total: {$requested} units. Verify the exact Draft proposal before recording its external authority.";
    }

    /** @return array<int, string> */
    private function coverageFeeOptions(): array
    {
        if (! $this->actor()->hasRole(User::StaffRoleAccounting)) {
            return [];
        }
        $position = $this->accountingViewData()['position'];

        return $position !== null && $position['state'] !== 'Unavailable'
            ? collect($position['obligations'])->filter(fn (array $row): bool => (float) $row['available_balance'] > 0)
                ->mapWithKeys(fn (array $row): array => [$row['id'] => $row['label'].' · '.$this->money($row['available_balance']).' available'])->all() : [];
    }

    /** @return Collection<int, ApprovedCoverage> */
    private function replacementCoverages(): Collection
    {
        if (! $this->actor()->hasRole(User::StaffRoleAccounting)) {
            return collect();
        }
        $context = $this->accountingViewData();
        if ($context['position'] === null || $context['position']['state'] === 'Unavailable') {
            return collect();
        }

        return $context['account']->coverages->where('state', ApprovedCoverage::StateApplied)
            ->filter(fn (ApprovedCoverage $coverage): bool => (int) $coverage->obligation?->assessment_id === (int) $context['assessment']?->id);
    }

    /** @return array<int, string> */
    private function replacementCoverageOptions(): array
    {
        return $this->replacementCoverages()->mapWithKeys(fn (ApprovedCoverage $coverage): array => [
            $coverage->id => AccountingDisplay::coverageCategoryLabel($coverage->category).' · '.$coverage->safe_source_description.' · '.$this->money($coverage->amount),
        ])->all();
    }

    /** @return array<int, string> */
    private function submittedEvidenceOptions(): array
    {
        return $this->getRecord()->termAccount?->paymentEvidenceVersions()
            ->where('state', PaymentEvidenceVersion::StateSubmitted)
            ->orderBy('submitted_at')
            ->get()
            ->mapWithKeys(fn (PaymentEvidenceVersion $evidence): array => [
                $evidence->id => "v{$evidence->version} — {$evidence->original_name} — {$this->money($evidence->claimed_amount)}",
            ])->all() ?? [];
    }

    private function maskedProviderReference(?string $reference): ?string
    {
        if (blank($reference)) {
            return null;
        }

        $reference = (string) $reference;

        return 'Provider …'.substr($reference, -6);
    }

    private function latestSubmittedEvidence(): ?PaymentEvidenceVersion
    {
        return $this->getRecord()->termAccount?->paymentEvidenceVersions()
            ->where('state', PaymentEvidenceVersion::StateSubmitted)
            ->latest('submitted_at')
            ->first();
    }

    public function selectedPaymentProof(): ?PaymentEvidenceVersion
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleAccounting), 403);
        $cacheKey = 'payment_proof:'.($this->getRecord()->termAccount?->id ?? 0).':'.($this->paymentProofId ?? 'latest');
        if (array_key_exists($cacheKey, $this->accountingMemo)) {
            return $this->accountingMemo[$cacheKey];
        }

        $proofs = $this->getRecord()->termAccount?->paymentEvidenceVersions();
        if ($this->paymentProofId !== null) {
            return $this->accountingMemo[$cacheKey] = $proofs?->where('state', PaymentEvidenceVersion::StateSubmitted)->find($this->paymentProofId);
        }

        return $this->accountingMemo[$cacheKey] = $proofs?->where('state', PaymentEvidenceVersion::StateSubmitted)->latest('submitted_at')->latest('id')->first();
    }

    private function selectedProofField(): Hidden
    {
        return Hidden::make('evidence_id')->required()->in(fn (): array => [$this->selectedPaymentProof()?->id]);
    }

    private function boundSubmittedProof(int $proofId): PaymentEvidenceVersion
    {
        $this->accountingMemo = [];
        if ($this->selectedPaymentProof()?->id !== $proofId) {
            throw ValidationException::withMessages(['evidence_id' => 'This receipt is no longer selected or awaiting review. Refresh the account.']);
        }

        return $this->getRecord()->termAccount->paymentEvidenceVersions()->where('state', PaymentEvidenceVersion::StateSubmitted)->findOrFail($proofId);
    }

    /** @return array<string, mixed> */
    public function paymentProofViewData(mixed $verifiedAmount = null): array
    {
        $proof = $this->selectedPaymentProof();
        $context = $this->accountingViewData();
        $money = app(DecimalMoney::class);
        $targetIds = collect($proof?->obligation_snapshot ?? [])->pluck('id');
        $targets = collect($context['position']['obligations'] ?? [])->whereIn('id', $targetIds)->values();
        $sourceCurrent = $proof !== null && ($context['position']['state'] ?? 'Unavailable') !== 'Unavailable'
            && (int) $context['position']['assessment_id'] === (int) $proof->assessment_id
            && $targetIds->isNotEmpty() && $targets->count() === $targetIds->count();
        $allocation = [];
        $allocationError = null;
        if ($verifiedAmount !== null) {
            try {
                $remaining = $money->toCents((string) $verifiedAmount);
                $available = $targets->sum(fn (array $row): int => $money->toCents($row['available_balance']));
                if (! $sourceCurrent) {
                    $allocationError = 'The captured assessment or payment targets changed. Refresh and resolve the account before posting.';
                } elseif ($remaining <= 0 || $remaining > $available) {
                    $allocationError = 'Enter a positive amount within the current unpaid balance of these targets.';
                } else {
                    foreach ($targets as $target) {
                        $balance = $money->toCents($target['available_balance']);
                        $applied = min($remaining, $balance);
                        $allocation[] = ['label' => $target['label'], 'applied' => $money->fromCents($applied), 'remaining' => $money->fromCents($balance - $applied)];
                        $remaining -= $applied;
                    }
                }
            } catch (\InvalidArgumentException) {
                $allocationError = 'Enter the independently verified amount to see how it will be applied.';
            }
        }

        $autoEnrollPreview = $this->autoEnrollPreview($this->getRecord(), $context, $allocation, $allocationError);

        return ['proof' => $proof, 'case' => $this->getRecord(), 'targets' => $targets,
            'targetBalance' => $sourceCurrent ? $money->fromCents($targets->sum(fn (array $row): int => $money->toCents($row['available_balance']))) : null,
            'sourceCurrent' => $sourceCurrent, 'allocation' => $allocation, 'allocationError' => $allocationError,
            'autoEnrollPreview' => $autoEnrollPreview,
            'pendingProofs' => $context['account']?->paymentEvidenceVersions->where('state', PaymentEvidenceVersion::StateSubmitted)->sortByDesc('submitted_at') ?? collect()];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<array{label:string,applied:string,remaining:string}>  $allocation
     * @return array{state:string,remaining?:string,blockers?:list<string>}|null
     */
    private function autoEnrollPreview(Enrollment $case, array $context, array $allocation, ?string $allocationError): ?array
    {
        if ($case->canonical_outcome !== Enrollment::OutcomeInProgress
            || $allocationError !== null
            || $allocation === []) {
            return null;
        }

        $requirement = $context['requirement'] ?? null;
        if (! is_array($requirement) || ($requirement['state'] ?? 'Unavailable') === 'Unavailable') {
            return null;
        }

        $money = app(DecimalMoney::class);
        $requiredLabels = collect($context['position']['obligations'] ?? [])
            ->where('required_for_enrollment', true)
            ->pluck('label')
            ->all();

        $appliedToRequiredCents = collect($allocation)
            ->filter(fn (array $row): bool => in_array($row['label'], $requiredLabels, true))
            ->sum(fn (array $row): int => $money->toCents($row['applied']));

        $currentRequiredBalanceCents = $money->toCents((string) ($requirement['balance'] ?? '0.00'));
        $remainingRequiredCents = max(0, $currentRequiredBalanceCents - $appliedToRequiredCents);

        if ($remainingRequiredCents > 0) {
            return [
                'state' => 'partial',
                'remaining' => $money->fromCents($remainingRequiredCents),
            ];
        }

        $readiness = app(RegistrationReadinessQuery::class)->for($case);
        $nonFinanceBlockers = array_values(array_filter(
            $readiness['blockers'],
            fn (string $blocker): bool => $blocker !== 'Accounting clearance',
        ));

        if ($nonFinanceBlockers === []) {
            return ['state' => 'ready'];
        }

        return [
            'state' => 'blocked',
            'blockers' => $nonFinanceBlockers,
        ];
    }

    private function sendPostPaymentAutoEnrollmentNotification(string $prefix, string $defaultSuccessTitle): void
    {
        $case = $this->getRecord();
        if ($case->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
            && $case->finalization_mode === Enrollment::FinalizationModeAutomatic
            && $case->currentCorVersion !== null) {
            Notification::make()
                ->title("{$prefix} · Student officially enrolled")
                ->body("COR-{$case->id}-{$case->currentCorVersion->version} issued automatically.")
                ->success()
                ->send();

            return;
        }

        if ($case->canonical_outcome === Enrollment::OutcomeInProgress) {
            $requirement = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case);
            if ($requirement['state'] === 'Cleared') {
                $readiness = app(RegistrationReadinessQuery::class)->for($case);
                $blockers = array_values(array_filter(
                    $readiness['blockers'],
                    fn (string $blocker): bool => $blocker !== 'Accounting clearance',
                ));

                if ($case->currentProposalVersion?->state === RegistrationProposalVersion::StateIssued
                    && $case->currentProposalVersion?->confirmation === null
                    && $readiness['identity']
                    && $readiness['eligibility']) {
                    Notification::make()
                        ->title($defaultSuccessTitle)
                        ->body('Payment applied. Enrollment will complete automatically once the student confirms subjects.')
                        ->success()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title("{$prefix} · Needs Registrar attention")
                    ->body('Payment applied, but automatic enrollment was blocked ('.implode(', ', $blockers).'). Routed to Registrar issues queue.')
                    ->warning()
                    ->send();

                return;
            }
        }

        Notification::make()
            ->title($defaultSuccessTitle)
            ->success()
            ->send();
    }

    private function failedOfficialEnrollmentNotification(): ?OperationalEvent
    {
        return OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainNotifications)
            ->where('event_type', OperationalEvent::TypeOfficialEnrollmentEmail)
            ->where('related_record_type', Enrollment::class)
            ->where('related_record_id', $this->getRecord()->id)
            ->where('status', OperationalEvent::StatusFailed)
            ->latest('id')
            ->first();
    }

    /** @return array<int, string> */
    private function openImpactReviewOptions(): array
    {
        $events = $this->getRecord()->registrationEvents()
            ->where('event_type', 'like', '%ImpactReviewOpened')
            ->orderBy('sequence')
            ->get();

        return $events->reject(function (RegistrationCaseEvent $opened): bool {
            $resolvedType = str_replace('Opened', 'Resolved', (string) $opened->event_type);

            return $this->getRecord()->registrationEvents()
                ->where('event_type', $resolvedType)
                ->where('authority_reference', $opened->authority_reference)
                ->exists();
        })->mapWithKeys(fn (RegistrationCaseEvent $event): array => [
            $event->id => $event->event_type.' — '.$event->authority_reference,
        ])->all();
    }

    private function isTimetableImpactEvent(mixed $eventId): bool
    {
        return $eventId !== null
            && RegistrationCaseEvent::query()
                ->whereKey((int) $eventId)
                ->where('event_type', 'TimetableRevisionImpactReviewOpened')
                ->exists();
    }

    private function failure(string $title, Throwable $exception): void
    {
        SafeUserNotification::sendError($title, $exception);
    }
}
