<?php

namespace App\Filament\Resources\Enrollments\Pages;

use App\Actions\Calendar\CalendarPhaseGateService;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
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
use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Actions\Enrollment\RegistrationPlacementValidator;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\ReopenRegistrationCase;
use App\Actions\Enrollment\StudentUnitLoadService;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\EnrollmentPaymentRequirementProjection;
use App\Actions\Finance\IndividualAssessmentSources;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Actions\Finance\RevalidateAssessmentEffects;
use App\Actions\Finance\ReverseApprovedCoverage;
use App\Actions\Finance\ReversePaymentPosting;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Finance\TermAccountProjection;
use App\Actions\Integrations\Payments\ExactDuePaymentSnapshotService;
use App\Actions\Integrations\Payments\PaymentAttemptSnapshotException;
use App\Actions\Integrations\Payments\PayMongoCheckoutRecoveryService;
use App\Actions\Integrations\Payments\PayMongoReconciliationService;
use App\Actions\Integrations\Payments\PayMongoWebhookEvent;
use App\Actions\Scheduling\ResolveTimetableRevisionRegistrationImpact;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Resources\Enrollments\Tables\EnrollmentsTable;
use App\Models\AdmissionApplication;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\AssessmentObligation;
use App\Models\CourseDropRecord;
use App\Models\CourseEnrollment;
use App\Models\CourseSpecification;
use App\Models\CurriculumEntry;
use App\Models\CurriculumVersion;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\FinanceExport;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvidenceVersion;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationCreditEvaluation;
use App\Models\RegistrationLateAuthority;
use App\Models\RegistrationProposalItem;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\TermOffering;
use App\Models\TimetableRevision;
use App\Models\User;
use App\Support\DecimalMoney;
use App\Support\RegistrationSchedulePresentation;
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
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

class ViewEnrollment extends ViewRecord
{
    protected static string $resource = EnrollmentResource::class;

    #[Url(as: 'view')]
    public string $enrollmentView = 'overview';

    #[Url(as: 'proof')]
    public ?int $paymentProofId = null;

    #[Url(as: 'attempt')]
    public ?int $onlineAttemptId = null;

    #[Url(as: 'event')]
    public ?int $onlineEventId = null;

    #[Url(as: 'webhook')]
    public ?int $webhookEventId = null;

    #[Locked]
    public bool $financeExportFailed = false;

    /** @var array<int, string> Reviewed coverage ID to source fingerprint, fixed when the reversal form opens. */
    #[Locked]
    public array $reviewedCoverage = [];

    /** @var array<int, string> Reviewed payment ID to source fingerprint, fixed when the refund form opens. */
    #[Locked]
    public array $reviewedPayments = [];

    /** @var array{account_id:int,attempt_id:int,event_id:int|null,fingerprint:string} Exact provider source reviewed for Accounting actions. */
    #[Locked]
    public array $reviewedOnlineCheckout = [];

    /** @var array{event_id:int,fingerprint:string} Exact webhook event reviewed for a direct retry. */
    #[Locked]
    public array $reviewedWebhookEvent = [];

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
        $this->reviewedProposalVersionId = $this->getRecord()->current_proposal_version_id;
        if ($this->isAccountingActor()) {
            $this->reviewedOnlineCheckout = $this->onlineCheckoutSnapshot();
            $event = $this->selectedWebhookEvent();
            if ($event !== null) {
                $this->reviewedWebhookEvent = ['event_id' => $event->id, 'fingerprint' => $this->webhookSourceFingerprint()];
            }
        }
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

    public function focusedAccountingView(): ?string
    {
        if (! $this->actor()->hasRole(User::StaffRoleAccounting)) {
            return null;
        }
        $context = $this->accountingViewData();
        if ($this->enrollmentView === 'history' && $context['account'] !== null) {
            return 'accounting-history';
        }
        if ($this->enrollmentView !== 'account' && $this->selectedWebhookEvent() !== null) {
            return 'accounting-webhook';
        }
        if (in_array($this->enrollmentView, ['overview', 'proof'], true) && $this->selectedPaymentProof() !== null) {
            return 'accounting-proof';
        }
        if ($this->enrollmentView !== 'account' && $this->selectedOnlineAttempt() !== null
            && $this->payMongoEventOptions(OperationalEvent::StatusReviewRequired, OperationalEvent::ChannelWebhook) === []
            && $this->failedPayMongoEventOptions() === []) {
            return 'accounting-online';
        }
        if ($context['assessment'] === null && $this->hasCurrentReservedProposal()) {
            return 'accounting-unassessed';
        }

        return $context['account'] !== null ? 'accounting-account' : null;
    }

    /** @return array<string, mixed> */
    public function accountingViewData(): array
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleAccounting), 403);
        if ($this->accountingContext !== null) {
            return $this->accountingContext;
        }
        $case = $this->getRecord();
        $case->loadMissing(['credentialUser', 'studentProfile.program', 'admissionApplication.program', 'term',
            'currentProposalVersion.items.section', 'currentProposalVersion.curriculumVersion.program',
            'termAccount.assessments.lines', 'termAccount.payments.verifier', 'termAccount.payments.reversals',
            'termAccount.payments.allocations.assessmentObligation', 'termAccount.coverages.obligation',
            'termAccount.paymentEvidenceVersions', 'termAccount.paymentAttempts', 'termAccount.financeExports']);
        $account = $case->termAccount;
        $assessment = $account?->assessments->where('state', Assessment::StateActive)->sortByDesc('version')->first();
        $position = $account !== null ? app(TermAccountProjection::class)->forAccount($account) : null;
        $requirement = app(EnrollmentPaymentRequirementProjection::class)->forEnrollment($case);
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
            'recordedAssessment' => $assessment ?? $account?->assessments->sortByDesc('version')->first(),
            'pendingAdjustment' => $pending, 'change' => $pending !== null ? $this->adjustmentChange() : null,
            'adjustmentFinanceReady' => $pending !== null && $pending->state === RegistrationProposalVersion::StateConfirmed && $this->adjustmentFinanceBases() !== [],
            'plan' => $assessment === null ? $this->applicablePublishedFeePlan() : null,
        ];
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
            ? app(IndividualAssessmentSources::class)->forEnrollment($this->getRecord())
            : ['initial' => false, 'adjustment' => false, 'course_drop' => null, 'categories' => []];
    }

    /** @param array<array-key, array<string, mixed>> $charges
     * @param  array<string, mixed>  $enrollmentPayment
     * @param  array<array-key, array<string, mixed>>  $laterPayments
     */
    private function assessmentDraftSummary(array $charges, array $enrollmentPayment, array $laterPayments): string
    {
        $total = collect($charges)->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));
        $payments = (float) ($enrollmentPayment['amount'] ?? 0) + collect($laterPayments)->sum(fn (array $row): float => (float) ($row['amount'] ?? 0));

        return 'Total charges: PHP '.number_format($total, 2).' · Total payments: PHP '.number_format($payments, 2).' · Difference: PHP '.number_format($total - $payments, 2).'. Charges and payment dates must add up to the same total.';
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

    public function getTitle(): string
    {
        if ($this->isHeadReader()) {
            return $this->getRecord()->credentialUser?->getFilamentName() ?? 'Enrollment record';
        }
        if ($this->focusedAccountingView() === 'accounting-history') {
            return 'Account history';
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
        return $this->enrollmentHeaderActions();
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
            if ($this->enrollmentView === 'history' || in_array($this->focusedAccountingView(), ['accounting-proof', 'accounting-online', 'accounting-webhook'], true)) {
                return [Action::make('backToAccount')->label('Back to account')->color('gray')->icon('heroicon-o-arrow-left')
                    ->url(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'account']))];
            }

            return [Action::make('backToAccounts')->label('Student accounts')->color('gray')->icon('heroicon-o-arrow-left')
                ->url(EnrollmentResource::getUrl('index'))];
        }
        $focusedView = $this->focusedRegistrarView();
        if ($focusedView !== null && $this->enrollmentView === 'history') {
            return [Action::make('backToEnrollment')->label('Back to enrollment')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]))];
        }
        if (in_array($focusedView, ['closed', 'preparing', 'payment-waiting', 'assessment-recovery', 'placement-recovery', 'requirements', 'adjustment', 'missing-cor', 'review'], true)) {
            return [Action::make('backToEnrollment')->label('Enrollment')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(EnrollmentResource::getUrl('index'))];
        }

        return [
            ...($focusedView !== null ? [Action::make('backToEnrollment')->label('Enrollment')->tooltip('Back to Enrollment')->icon('heroicon-o-arrow-left')->color('gray')
                ->url(EnrollmentResource::getUrl('index'))] : [$this->nextTaskAction()]),
            Action::make('resendOfficialEnrollmentEmail')
                ->label('Resend enrollment email')
                ->icon('heroicon-o-arrow-path')
                ->tooltip('Send the failed enrollment email again to the learner.')
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
                    ->label('Print current COR')
                    ->icon('heroicon-o-printer')
                    ->url(fn (): string => route('cor.print', $this->getRecord()))
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => $focusedView !== 'official' && $this->getRecord()->current_cor_version_id !== null),
                Action::make('printCorHistory')
                    ->label('Certificate copies')
                    ->icon('heroicon-o-document-duplicate')
                    ->visible(fn (): bool => $focusedView !== 'official' && $this->getRecord()->corVersions()->exists())
                    ->url(fn (): string => EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']).'#certificate-copies'),
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
        return Action::make('reconcileAssessmentEffects')->label('Reconcile retained payment and coverage')
            ->icon('heroicon-o-arrows-right-left')
            ->authorize(fn (): bool => $this->isAccountingActor())
            ->visible(function (): bool {
                $account = $this->getRecord()->termAccount;

                return $this->isAccountingActor() && $account !== null
                    && (app(TermAccountProjection::class)->forAccount($account)['reconciliation_required'] ?? false);
            })
            ->modalHeading('Apply earlier payments and coverage')
            ->modalDescription('Choose which current payment each earlier amount should count toward. Original records stay in account history.')
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
                    Notification::make()->title('Retained financial effects reconciled')->success()->send();
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

    private function money(mixed $amount): string
    {
        return 'PHP '.number_format((float) $amount, 2);
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
                'line' => 'Earlier assessment v'.$earlier->get($source['id'])?->assessment?->version.' · '.$source['label']
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
                $obligation->id => $obligation->label.' (PHP '.number_format((float) $obligation->amount, 2).')',
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
            : $targets->map(fn (array $target): string => ($target['label'] ?? $target['code'] ?? 'Obligation '.($target['id'] ?? $target['assessment_obligation_id'] ?? '')).' · '.(isset($target['available_balance']) ? 'PHP '.number_format((float) $target['available_balance'], 2) : 'Captured amount unavailable'))->implode('; '))
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
        return Action::make('recordAcademicEvaluation')->label('Record evaluation')->icon('heroicon-o-academic-cap')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress
                && $this->getRecord()->admissionApplication?->application_path === AdmissionApplication::PathTransferee)
            ->modalHeading('Record transfer credit evaluation')->modalWidth(Width::TwoExtraLarge)
            ->modalDescription('Record the school’s approved result. This does not approve new credits.')
            ->modalSubmitActionLabel('Save evaluation')->modalCancelActionLabel('Cancel')
            ->fillForm(function (): array {
                $case = $this->getRecord()->fresh();
                $latest = RegistrationCreditEvaluation::query()->where('enrollment_id', $case->id)->latest('id')->first();
                $this->reviewedAcademicEvaluation = ['submission' => (int) $case->admissionApplication->current_submission_version_id,
                    'lock' => $case->lock_version, 'evaluation' => $latest?->id];

                return ['curriculum_version_id' => $latest?->curriculum_version_id, 'result' => $latest !== null ? ($latest->credited_curriculum_entry_ids === [] ? 'none' : 'credits') : null,
                    'credited_curriculum_entry_ids' => $latest?->credited_curriculum_entry_ids ?? [],
                    'authority_reference' => null, 'approved_on' => null];
            })
            ->schema([
                Text::make(fn (): string => $this->getRecord()->credentialUser->getFilamentName().' · '.$this->getRecord()->admissionApplication->program->name.' · '.$this->getRecord()->term->label),
                Select::make('curriculum_version_id')->label('Curriculum')->required()->live()
                    ->options(fn (): array => CurriculumVersion::query()->where('program_id', $this->getRecord()->admissionApplication?->program_id)->where('state', CurriculumVersion::StateActive)->pluck('name', 'id')->all())
                    ->afterStateUpdated(fn (Set $set) => $set('credited_curriculum_entry_ids', [])),
                Radio::make('result')->label('Approved result')->options(['credits' => 'Subjects credited', 'none' => 'No subjects credited'])->required()->live()->inline(),
                Select::make('credited_curriculum_entry_ids')->label('Credited subjects')->multiple()->searchable()->preload()
                    ->options(fn (Get $get): array => CurriculumEntry::query()->with('courseSpecification.course')->where('curriculum_version_id', $get('curriculum_version_id'))->get()
                        ->mapWithKeys(fn (CurriculumEntry $entry): array => [$entry->id => $entry->courseSpecification->course->code.' · '.$entry->courseSpecification->title])->all())
                    ->visible(fn (Get $get): bool => $get('result') === 'credits')->required(fn (Get $get): bool => $get('result') === 'credits')
                    ->helperText('Only these requirements count as credited.'),
                Grid::make(2)->schema([TextInput::make('authority_reference')->label('Approval reference')->required()->maxLength(255),
                    DatePicker::make('approved_on')->label('Approval date')->required()->maxDate(today())]),
                Text::make('Recorded by you automatically. Earlier evaluations remain in history. Replacing an evaluation requires a fresh subject proposal.'),
            ])->action(function (array $data): void {
                $review = $this->reviewedAcademicEvaluation;
                if ($review === null) {
                    throw ValidationException::withMessages(['evaluation' => 'Reopen the academic evaluation.']);
                }
                app(RecordRegistrationCreditEvaluation::class)->execute($this->getRecord(), $this->actor(),
                    $data['result'] === 'credits' ? ($data['credited_curriculum_entry_ids'] ?? []) : [],
                    $data['authority_reference'], CarbonImmutable::parse($data['approved_on']), $review['submission'],
                    (int) $data['curriculum_version_id'], $review['lock'], $review['evaluation']);
                Notification::make()->success()->title('Academic evaluation recorded')->send();
                $this->getRecord()->refresh();
            });
    }

    public function prepareProposalAction(): Action
    {
        return Action::make('prepareProposal')
            ->label($this->getRecord()->currentProposalVersion === null ? 'Choose subjects' : 'Revise subjects')
            ->color($this->getRecord()->currentProposalVersion === null ? 'primary' : 'gray')
            ->icon('heroicon-o-document-plus')
            ->modalHeading('Choose subjects')
            ->modalDescription(fn (): string => ($this->getRecord()->credentialUser?->getFilamentName() ?? 'Learner').' · '.$this->getRecord()->term?->label.'. Saving prepares a draft. Seats and assessment are checked next.')
            ->modalSubmitActionLabel('Save subjects')
            ->modalWidth(Width::FourExtraLarge)
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal registrar-subject-selection-modal'])
            ->authorize(fn (): bool => $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress
                && ($this->actor()->can('confirmPlacement', $this->getRecord())))
            ->fillForm(fn (): array => [
                'case_version' => $this->getRecord()->lock_version,
                'section_ids' => $this->getRecord()->currentProposalVersion?->items->pluck('section_id')->all() ?? [],
            ])
            ->schema([
                Hidden::make('case_version')->required(),
                CheckboxList::make('section_ids')
                    ->label('Subjects and classes')
                    ->options(fn (): array => $this->sectionOptions())
                    ->descriptions(fn (): array => $this->sectionDescriptions())
                    ->searchable()
                    ->searchPrompt('Find a subject or class')
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
                    app(PrepareRegistrationProposal::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        array_map('intval', $data['section_ids']),
                        (int) $data['case_version'],
                        curriculumPosition: $data['curriculum_position'] ?? null,
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Subjects saved for review')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
                } catch (Throwable $exception) {
                    $this->failure('Subjects were not saved', $exception);
                }
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
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalSubmitActionLabel('Record approval')->modalCancelActionLabel('Keep under review')
            ->fillForm(fn (): array => ['proposal_id' => $this->getRecord()->current_proposal_version_id, 'reviewed_source' => $this->overloadFingerprint()])
            ->modalContent(fn () => view('filament.admin.enrollments.review-source-context', ['review' => $this->registrarReviewData()]))
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->getRecord()->currentProposalVersion?->state === RegistrationProposalVersion::StateDraft
                && data_get($this->getRecord()->currentProposalVersion->source_snapshot, 'unit_load.requires_graduating_overload') === true)
            ->schema([
                Hidden::make('proposal_id')->required(),
                Hidden::make('reviewed_source')->required(),
                TextInput::make('authority_reference')
                    ->label('School approval reference')
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
                    app(IssueRegistrationProposal::class)->execute($proposal, $this->actor());
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Subjects sent for confirmation')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
            ->modalDescription('Record only confirmation the student has already given for these reviewed subjects. This records their consent; enrollment becomes official only after Registrar finalization.')
            ->modalSubmitActionLabel('Record confirmation')
            ->modalWidth(Width::TwoExtraLarge)
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
                } catch (Throwable $exception) {
                    $this->failure('Seats were not reserved', $exception);
                }
            });
    }

    public function createAssessmentAction(): Action
    {
        return Action::make('createAssessment')
            ->label('Use Fee Plan')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->hasCurrentReservedProposal() && $this->applicablePublishedFeePlan() !== null)
            ->authorize(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting))
            ->modalHeading('Use published Fee Plan')
            ->modalDescription('Check the approved charges and payment dates before recording this assessment.')
            ->modalWidth(Width::ThreeExtraLarge)
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
            ->label('Record individual assessment')
            ->icon('heroicon-o-document-currency-dollar')
            ->modalHeading('Record individual assessment')
            ->modalDescription('Record school-approved fixed charges and payment dates for this assessment.')
            ->modalWidth(Width::SevenExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal accounting-assessment-modal'])
            ->modalSubmitActionLabel('Record assessment')
            ->authorize(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $sources['categories'] !== [])
            ->fillForm(fn (): array => [
                'reviewed_proposal_id' => $this->getRecord()->current_proposal_version_id,
                'reviewed_drop_id' => $sources['course_drop']?->id,
                'category' => array_key_first($categories),
                'charges' => [['label' => '', 'amount' => null]],
                'enrollment_payment' => ['amount' => null, 'due_at' => null],
                'later_payments' => [],
            ])
            ->schema([
                Hidden::make('reviewed_proposal_id')->required(),
                Hidden::make('reviewed_drop_id'),
                Text::make(fn (): string => ($this->getRecord()->credentialUser?->getFilamentName() ?? 'Learner').' · '.$this->getRecord()->term?->label)
                    ->extraAttributes(['class' => 'accounting-assessment-context'])->columnSpanFull(),
                ...$categoryFields,
                SchemaSection::make('Charges')
                    ->description('Add each approved charge and its amount for this assessment.')
                    ->contained(false)
                    ->extraAttributes(['class' => 'accounting-finance-form-section'])
                    ->schema([
                        Repeater::make('charges')->label('Charges')->hiddenLabel()->addActionLabel('Add charge')->reorderable(false)
                            ->schema([
                                TextInput::make('label')->label('Charge name')->required()->maxLength(255),
                                TextInput::make('amount')->label('Amount')->numeric()->minValue(0)->prefix('PHP')->required()->live(onBlur: true),
                            ])->minItems(1)->columns(['default' => 1, 'md' => 2]),
                    ])->columnSpanFull(),
                SchemaSection::make(fn (Get $get): string => $get('category') === Assessment::CategoryAuthorizedAdjustment ? 'Payment dates for this change' : 'Payment dates')
                    ->description('Set the amount and due date for each payment. Dates use '.config('app.display_timezone').'.')
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
                            ->schema([
                                Text::make('Later payment')->weight('medium'),
                                TextInput::make('amount')->label('Amount')->numeric()->minValue(0)->prefix('PHP')->required()->live(onBlur: true),
                                DateTimePicker::make('due_at')->label('Due date and time')->timezone(config('app.display_timezone'))->helperText(config('app.display_timezone'))->required()->seconds(false),
                            ])->columns(['default' => 1, 'md' => 3]),
                    ])->columnSpanFull(),
                SchemaSection::make('School approval')
                    ->description('Provide the school approval details for this assessment.')
                    ->contained(false)
                    ->extraAttributes(['class' => 'accounting-finance-form-section'])
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2])->schema([
                            TextInput::make('authority_reference')->label('Approval reference')->required()->maxLength(255),
                            DatePicker::make('authority_date')->label('Approval date')->required(),
                        ]),
                    ])->columnSpanFull(),
                Text::make(fn (Get $get): string => $this->assessmentDraftSummary($get('charges') ?? [], $get('enrollment_payment') ?? [], $get('later_payments') ?? []))
                    ->extraAttributes(['class' => 'accounting-assessment-summary'])->columnSpanFull(),
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
            ->modalWidth(Width::ThreeExtraLarge)
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->authorize(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && ($this->coverageFeeOptions() !== [] || $replacements !== []))
            ->fillForm(fn (): array => [
                'reviewed_assessment_id' => $this->accountingViewData()['assessment']?->id,
                'mode' => $this->coverageFeeOptions() !== [] ? 'new' : 'replace',
            ])
            ->schema([
                Hidden::make('reviewed_assessment_id')->required(),
                Text::make(fn (): string => ($this->getRecord()->credentialUser?->getFilamentName() ?? 'Learner').' · '.$this->getRecord()->term?->label),
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

                    return $previous ? $previous->obligation?->label.' · Existing coverage PHP '.number_format((float) $previous->amount, 2).'. The replacement remains on this fee. Previous approval stays in account history.' : 'Choose the existing approval to replace.';
                })->visible(fn (Get $get): bool => $get('mode') === 'replace'),
                Select::make('obligation_id')->label('Fee to cover')->options(fn (): array => $this->coverageFeeOptions())
                    ->helperText('Only fees with available balance can receive new coverage.')
                    ->visible(fn (Get $get): bool => $get('mode') !== 'replace')
                    ->required(fn (Get $get): bool => $get('mode') !== 'replace'),
                Grid::make(['md' => 2])->schema([
                    Select::make('category')->label('Coverage type')->options(collect(ApprovedCoverage::Categories)->mapWithKeys(fn (string $category): array => [$category => Str::headline($category)])->all())->required(),
                    TextInput::make('amount')->label('Amount')->numeric()->minValue(0.01)->prefix('PHP')->required(),
                ]),
                TextInput::make('safe_source_description')->label('Funding source')->required()->maxLength(255),
                Grid::make(['md' => 2])->schema([
                    TextInput::make('authority_reference')->label('Approval reference')->required()->maxLength(255),
                    DatePicker::make('authority_date')->label('Approval date')->required(),
                ]),
                DatePicker::make('effective_date')->label('Effective from')->helperText('Coverage reduces the balance from this date.')->required(),
            ])
            ->action(function (array $data): void {
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
            ->icon('heroicon-o-check-circle')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->selectedPaymentProof() !== null)
            ->fillForm(fn (): array => ['evidence_id' => $this->selectedPaymentProof()?->id,
                'actual_verified_amount' => $this->selectedPaymentProof()?->claimed_amount])
            ->schema([
                $this->selectedProofField(),
                SchemaView::make('filament.admin.enrollments.payment-proof-context')->viewData(fn (): array => $this->paymentProofViewData()),
                TextInput::make('actual_verified_amount')->label('Verified amount')->numeric()->minValue(0.01)->prefix('PHP')->required()->live(onBlur: true)
                    ->helperText('Enter the amount confirmed by the independent source.'),
                TextInput::make('external_check_reference')->label('Independent verification result')->required()->maxLength(255)
                    ->helperText('Record the bank or collecting-office result used to verify this payment.'),
                SchemaView::make('filament.admin.enrollments.payment-proof-allocation')
                    ->viewData(fn (Get $get): array => $this->paymentProofViewData($get('actual_verified_amount'))),
            ])
            ->action(function (array $data): void {
                try {
                    app(ReviewPaymentEvidence::class)->verify(
                        $this->boundSubmittedProof((int) $data['evidence_id']),
                        $this->actor(),
                        (string) $data['actual_verified_amount'],
                        $data['external_check_reference'],
                    );
                    Notification::make()->title('Payment evidence verified')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Evidence was not verified', $exception);
                }
            });
    }

    public function retrievePayMongoCheckoutAction(): Action
    {
        return Action::make('retrievePayMongoCheckout')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleAccounting))
            ->label('Check provider status')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->canRetrieveSelectedCheckout())
            ->action(function (): void {
                try {
                    $attemptId = $this->assertReviewedOnlineCheckout();
                    $result = app(PayMongoCheckoutRecoveryService::class)->recover(
                        $attemptId,
                        $this->actor(),
                    );
                    Notification::make()
                        ->title('PayMongo checkout retrieved')
                        ->body('Provider state: '.$result['status'])
                        ->success()
                        ->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'online', 'attempt' => $attemptId]));
                } catch (Throwable $exception) {
                    $this->failure('PayMongo checkout was not retrieved', $exception);
                }
            });
    }

    public function confirmRecoveredPayMongoAction(): Action
    {
        return Action::make('confirmRecoveredPayMongo')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleAccounting))
            ->label('Confirm payment')
            ->modalHeading('Confirm recovered payment')
            ->modalSubmitActionLabel('Confirm payment')
            ->modalCancelActionLabel('Keep under review')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalIcon('heroicon-o-check-badge')
            ->icon('heroicon-o-check-badge')
            ->color('primary')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->selectedRecoveredOnlineEvent() !== null)
            ->disabled(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && ! $this->onlinePaymentViewData()['canConfirm'])
            ->mountUsing(function (): void {
                $this->reviewedOnlineCheckout = $this->onlineCheckoutSnapshot();
            })
            ->fillForm(fn (): array => ['event_id' => $this->selectedRecoveredOnlineEvent()?->id])
            ->schema([
                Hidden::make('event_id')->required()->in(fn (): array => [$this->selectedRecoveredOnlineEvent()?->id]),
                SchemaView::make('filament.admin.enrollments.online-payment-context')->viewData(fn (): array => $this->onlinePaymentViewData() + ['showEffect' => true]),
            ])
            ->modalDescription('This records the reported amount against the payments shown below.')
            ->action(function (array $data): void {
                try {
                    $this->decideRecoveredOnlinePayment($data, confirm: true);
                    Notification::make()->title('Recovered PayMongo payment posted')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Recovered PayMongo payment was not posted', $exception);
                }
            });
    }

    public function rejectRecoveredPayMongoAction(): Action
    {
        return Action::make('rejectRecoveredPayMongo')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleAccounting))
            ->label('Reject result')
            ->modalHeading('Reject recovered result')
            ->modalSubmitActionLabel('Reject result')
            ->modalCancelActionLabel('Keep under review')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalIcon(null)
            ->modalDescription('Rejection marks this attempt as failed and records no payment. The result and decision remain in account history.')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->selectedRecoveredOnlineEvent() !== null)
            ->fillForm(fn (): array => ['event_id' => $this->selectedRecoveredOnlineEvent()?->id])
            ->schema([
                Hidden::make('event_id')->required()->in(fn (): array => [$this->selectedRecoveredOnlineEvent()?->id]),
                SchemaView::make('filament.admin.enrollments.online-payment-context')->viewData(fn (): array => $this->onlinePaymentViewData()),
                Textarea::make('reason')->label('Reason for the decision')->helperText('Explain why this recovered result cannot be accepted.')->minLength(5)->maxLength(1000)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    $this->decideRecoveredOnlinePayment($data, confirm: false);
                    Notification::make()->title('Recovered evidence rejected')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Recovered evidence was not rejected', $exception);
                }
            });
    }

    public function retryPayMongoEventAction(): Action
    {
        return Action::make('retryPayMongoEvent')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleAccounting))
            ->label('Retry processing')->color('gray')->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $this->webhookPaymentViewData()['canRetryWebhook'])
            ->action(function (): void {
                try {
                    $reviewedEvent = $this->reviewedWebhookEvent;
                    abort_unless(isset($reviewedEvent['event_id'], $reviewedEvent['fingerprint'])
                        && $reviewedEvent['event_id'] === $this->selectedWebhookEvent()?->id, 404);
                    $this->decideWebhook([
                        'event_id' => $reviewedEvent['event_id'],
                        'reviewed_source' => $reviewedEvent['fingerprint'],
                        'reason' => 'Accounting requested retry of the retained delivery.',
                    ], 'retry');
                    Notification::make()->title('Original payment delivery queued again')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Payment processing was not queued', $exception);
                }
            });
    }

    public function linkUnknownPayMongoEventAction(): Action
    {
        return Action::make('linkUnknownPayMongoEvent')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleAccounting))
            ->label('Link and retry')->icon('heroicon-o-link')->modalHeading('Link result and retry')
            ->modalDescription('Link this result to the selected checkout and queue the original delivery. Payment and reference checks still apply; this does not confirm payment immediately.')
            ->modalSubmitActionLabel('Link and retry')->modalCancelActionLabel('Keep under review')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $this->webhookPaymentViewData()['canLinkWebhook'])
            ->fillForm(fn (): array => ['event_id' => $this->selectedWebhookEvent()?->id, 'reviewed_source' => $this->webhookSourceFingerprint()])
            ->schema([
                ...$this->webhookBoundFields(),
                SchemaView::make('filament.admin.enrollments.webhook-payment-context')->viewData(fn (): array => $this->webhookPaymentViewData()),
                Textarea::make('reason')->label('Linkage evidence')->helperText('Record the source facts that connect this result to the selected account and checkout.')->minLength(5)->maxLength(1000)->required(),
            ])->action(function (array $data): void {
                try {
                    $this->decideWebhook($data, 'link');
                    Notification::make()->title('Payment result linked and queued')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Payment result was not linked', $exception);
                }
            });
    }

    public function confirmPayMongoExceptionAction(): Action
    {
        return Action::make('confirmPayMongoException')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleAccounting))
            ->label('Confirm payment')->icon('heroicon-o-check-circle')
            ->modalHeading('Confirm reference discrepancy')->modalSubmitActionLabel('Confirm payment')->modalCancelActionLabel('Keep under review')
            ->modalDescription('Confirmation records one verified payment to these targets. The reference discrepancy and your reason remain in account history.')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $this->webhookPaymentViewData()['canConfirmWebhook'])
            ->fillForm(fn (): array => ['event_id' => $this->selectedWebhookEvent()?->id, 'reviewed_source' => $this->webhookSourceFingerprint()])
            ->schema([
                ...$this->webhookBoundFields(),
                SchemaView::make('filament.admin.enrollments.webhook-payment-context')->viewData(fn (): array => $this->webhookPaymentViewData() + ['showEffect' => true]),
                Textarea::make('reason')->label('Accounting decision basis')->helperText('Explain how you established that this result belongs to the selected checkout.')->minLength(5)->maxLength(1000)->required(),
            ])->action(function (array $data): void {
                try {
                    $this->decideWebhook($data, 'confirm');
                    Notification::make()->title('Payment exception confirmed')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Payment exception was not confirmed', $exception);
                }
            });
    }

    public function rejectPayMongoExceptionAction(): Action
    {
        return Action::make('rejectPayMongoException')
            ->authorize(fn (): bool => $this->actor()->canAuthenticate() && $this->actor()->hasRole(User::StaffRoleAccounting))
            ->label('Reject result')->icon('heroicon-o-no-symbol')->color('danger')
            ->modalHeading('Reject payment result')->modalSubmitActionLabel('Reject result')->modalCancelActionLabel('Keep under review')
            ->modalDescription('The result and decision remain in account history. An unverified claim is rejected without recording payment; its linked active attempt is marked failed.')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $this->webhookPaymentViewData()['canRejectWebhook'])
            ->fillForm(fn (): array => ['event_id' => $this->selectedWebhookEvent()?->id, 'reviewed_source' => $this->webhookSourceFingerprint()])
            ->schema([
                ...$this->webhookBoundFields(),
                SchemaView::make('filament.admin.enrollments.webhook-payment-context')->viewData(fn (): array => $this->webhookPaymentViewData()),
                Textarea::make('reason')->label('Reason for rejection')->helperText('Explain why the reported result cannot be accepted.')->minLength(5)->maxLength(1000)->required(),
            ])->action(function (array $data): void {
                try {
                    $this->decideWebhook($data, 'reject');
                    Notification::make()->title('Payment result rejected')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Payment result was not rejected', $exception);
                }
            });
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
            ->modalDescription('Corrects this Term account. It does not refund money or contact a provider.')
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
                TextInput::make('authority_reference')->label('Reversal approval reference')->required()->maxLength(255)
                    ->helperText('The external approval for this correction, not the original coverage reference.'),
                Textarea::make('safe_reason')->label('Reason')->required()->maxLength(1000)
                    ->helperText('Use an explanation that is safe for the learner to read. Leave out private eligibility details.'),
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
            ->label('Reverse verified payment')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->authorize(fn (): bool => $this->isAccountingActor())
            ->visible(fn (): bool => $this->isAccountingActor() && $this->reversiblePayments()->isNotEmpty())
            ->modalHeading('Record a completed refund')
            ->modalDescription('Record an externally completed refund against this verified payment. This action does not send money or contact a provider.')
            ->stickyModalFooter()
            ->modalSubmitActionLabel('Record refund')->modalCancelActionLabel('Keep payment unchanged')
            ->modalSubmitAction(fn (Action $action): Action|false => $this->reviewedPayments !== [] ? $action : false)
            ->fillForm(function (): array {
                $this->reviewedPayments = $this->reversiblePayments()->mapWithKeys(fn (Payment $payment): array => [$payment->id => $this->sourceFingerprint($payment)])->all();

                return [];
            })
            ->schema([
                Select::make('payment_id')->label('Payment to correct')->placeholder('Choose the payment')->required()->live()
                    ->options(fn (): array => $this->paymentChoices())->visible(fn (): bool => count($this->reviewedPayments) > 1)
                    ->afterStateUpdated(fn (Set $set): mixed => $set('paymongo_event_id', null)),
                SchemaView::make('filament.admin.enrollments.accounting-correction-context')
                    ->viewData(fn (Get $get): array => $this->paymentContext($get('payment_id'))),
                TextInput::make('authority_reference')->label('Refund approval reference')->required()->maxLength(255),
                Textarea::make('safe_reason')->label('Reason')->required()->maxLength(1000)
                    ->helperText('Use a learner-safe explanation of this correction.'),
                Select::make('paymongo_event_id')->label('Matching PayMongo refund or reversal evidence')->placeholder('None')
                    ->options(fn (Get $get): array => $this->refundEventChoices($get('payment_id')))
                    ->visible(fn (Get $get): bool => $this->refundEventChoices($get('payment_id')) !== [])
                    ->helperText('Choose it only when this refund resolves provider evidence waiting for review. A pending or failed delivery is not proof of a completed refund.'),
                TextInput::make('external_refund_reference')->label('Completed external refund reference')->required()->maxLength(255),
                TextInput::make('refund_amount')->label('Actual refunded amount')->numeric()->minValue(0.01)->prefix('PHP')->required()
                    ->maxValue(fn (Get $get): float => $this->paymentRemaining($get('payment_id'))),
                DateTimePicker::make('refunded_at')->label('Completed refund date and time')->timezone(config('app.display_timezone'))->helperText(config('app.display_timezone'))->required()->maxDate(fn () => now(config('app.display_timezone'))),
                Text::make('This records a reversal against the original named allocations and recalculates the account. The original payment and official enrollment remain in history.')->color('neutral'),
            ])->action(function (array $data): void {
                try {
                    $payment = $this->reviewedPaymentSource($data['payment_id'] ?? null);
                    $eventId = filled($data['paymongo_event_id'] ?? null) ? (int) $data['paymongo_event_id'] : null;
                    if ($eventId !== null && ! array_key_exists($eventId, $this->refundEventChoices($payment->id))) {
                        throw ValidationException::withMessages(['paymongo_event_id' => 'This provider evidence does not belong to the selected payment. Choose again or leave it empty.']);
                    }
                    DB::transaction(function () use ($data, $payment, $eventId): void {
                        $reversal = app(ReversePaymentPosting::class)->execute(
                            $payment,
                            $this->actor(), $data['authority_reference'], $data['safe_reason'],
                            $data['external_refund_reference'], (string) $data['refund_amount'], $data['refunded_at'],
                        );
                        if ($eventId !== null) {
                            app(PayMongoReconciliationService::class)->recordRefundReversal(
                                $eventId,
                                $payment,
                                $reversal,
                                (string) $data['safe_reason'],
                                $this->actor(),
                            );
                        }
                    });
                    Notification::make()->title('Payment reversal recorded')->success()->send();
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
                $coverage->id => $this->money($coverage->amount).' · '.Str::headline($coverage->category).' · '.$coverage->obligation?->label,
            ])->all();
    }

    /** @return array<int, string> */
    private function paymentChoices(): array
    {
        return $this->reversiblePayments()->filter(fn (Payment $payment): bool => array_key_exists($payment->id, $this->reviewedPayments))
            ->mapWithKeys(fn (Payment $payment): array => [
                $payment->id => $this->money($this->remainingReversible($payment)).' left · '.$payment->channelLabel().' · '.$payment->paid_at?->timezone(config('app.display_timezone'))->format('M j, Y'),
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
            'Amount' => $this->money($coverage->amount).' · '.$coverage->state,
            'Applies to' => $coverage->obligation?->label,
            'Category and source' => Str::headline($coverage->category).' · '.$coverage->safe_source_description,
            'Original approval' => collect([$coverage->authority_reference, $coverage->authority_date?->format('M j, Y')])->filter()->implode(' · '),
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

    /** @return array<int, string> */
    private function refundEventChoices(mixed $chosen): array
    {
        $id = $this->reviewedSourceId($this->reviewedPayments, $chosen);
        if ($id === null) {
            return [];
        }

        return OperationalEvent::query()
            ->where('integration', OperationalEvent::IntegrationPayMongo)
            ->where('channel', OperationalEvent::ChannelWebhook)
            ->where('status', OperationalEvent::StatusReviewRequired)
            ->whereIn('diagnostics->reason', ['refund_or_reversal'])
            ->where('related_record_type', Payment::class)->where('related_record_id', $id)
            ->orderByDesc('id')->get()
            ->mapWithKeys(fn (OperationalEvent $event): array => [$event->id => 'Event #'.$event->id.' — '.$event->event_type])
            ->all();
    }

    public function exportTermAccountAction(): Action
    {
        return Action::make('exportTermAccount')
            ->label('Export verified payments')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Export verified payments')
            ->modalDescription('Review verified payments and reversals for this account.')
            ->modalIcon(null)
            ->modalWidth(Width::FiveExtraLarge)
            ->extraModalWindowAttributes(['autofocus' => true, 'tabindex' => '-1'])->stickyModalHeader()->modalFooterActionsAlignment(Alignment::End)->stickyModalFooter()
            ->modalSubmitActionLabel(fn (): string => $this->financeExportFailed ? 'Try download again' : 'Download CSV')
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
            ->label('Download submitted evidence')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->latestSubmittedEvidence() instanceof PaymentEvidenceVersion)
            ->url(fn (): string => route('finance.payment-evidence.download', $this->latestSubmittedEvidence()))
            ->openUrlInNewTab();
    }

    public function rejectPaymentEvidenceAction(): Action
    {
        return Action::make('rejectPaymentEvidence')
            ->label('Reject proof')
            ->modalHeading('Reject payment proof')
            ->modalDescription('The proof and reason stay in account history. The learner can submit a corrected receipt.')
            ->modalSubmitActionLabel('Reject proof')
            ->modalCancelActionLabel('Keep under review')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->selectedPaymentProof() !== null)
            ->fillForm(fn (): array => ['evidence_id' => $this->selectedPaymentProof()?->id])
            ->schema([
                $this->selectedProofField(),
                SchemaView::make('filament.admin.enrollments.payment-proof-context')->viewData(fn (): array => $this->paymentProofViewData()),
                Textarea::make('safe_reason')->label('Reason for the learner')->helperText('Explain what could not be verified and what the learner should correct.')->required()->maxLength(1000),
            ])
            ->action(function (array $data): void {
                try {
                    app(ReviewPaymentEvidence::class)->reject(
                        $this->boundSubmittedProof((int) $data['evidence_id']),
                        $this->actor(),
                        $data['safe_reason'],
                    );
                    Notification::make()->title('Payment evidence rejected')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord(), 'view' => 'history']));
                } catch (Throwable $exception) {
                    $this->failure('Evidence was not rejected', $exception);
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
            ->modalHeading('Apply the confirmed change')->modalSubmitActionLabel('Apply change')->modalCancelActionLabel('Keep current registration')->stickyModalFooter()
            ->modalDescription('This applies the confirmed change and issues an updated certificate. Earlier registration and certificate versions remain in history.')
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
            $options['assessment:'.$assessment->id] = 'Cleared successor assessment - '.$assessment->authority_reference;
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
                } catch (Throwable $exception) {
                    $this->failure('Adjustment proposal was not prepared', $exception);
                }
            });
    }

    public function confirmNoAdditionalCostAction(): Action
    {
        return Action::make('confirmNoAdditionalCost')
            ->label('Confirm no additional cost')
            ->icon('heroicon-o-banknotes')
            ->authorize(fn (): bool => $this->isAccountingActor())
            ->visible(fn (): bool => $this->isAccountingActor() && $this->noCostReviewContext() !== [])
            ->modalHeading('Confirm no additional cost')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-banknotes')
            ->modalWidth(Width::Large)
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
            ->modalSubmitActionLabel('Record Course Drop')->modalCancelActionLabel('Keep current registration')->stickyModalFooter()
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
                    Notification::make()->title('Course Drop recorded')->body('Accounting review is now required; earlier registration and certificate versions remain in history.')->success()->send();
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
            ->extraModalWindowAttributes(['class' => 'registrar-enrollment-entry-modal'])
            ->modalDescription('Release temporary seats and stop this enrollment. Existing payments and history remain recorded. Cancellation does not issue a refund.')
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
            ->modalHeading('Record late approval')->modalSubmitActionLabel('Record approval')->modalCancelActionLabel('Keep current registration')->stickyModalFooter()
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
                        TextInput::make('authority_reference')->label('School approval reference')->required()->maxLength(255),
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
                    $this->redirect(EnrollmentResource::getUrl('view', ['record' => $this->getRecord()]));
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
                TextInput::make('authority_reference')->label('Approval reference')->required()->maxLength(255),
            ])
            ->modalDescription('Record the actual approval received for this learner, case and Term. This does not reopen enrollment.')
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

    /** @return array<int, string> */
    private function sectionOptions(): array
    {
        return $this->classChoices()['sections']->mapWithKeys(fn (Section $section): array => [
            $section->id => collect([
                $section->termOffering?->courseSpecification()?->title,
                $section->termOffering?->course()?->code,
                $section->code,
            ])->filter()->implode(' · '),
        ])->all();
    }

    /** @return array{sections: Collection<int, Section>, meetings: Collection} */
    private function classChoices(): array
    {
        if ($this->classChoiceContext !== null) {
            return $this->classChoiceContext;
        }

        $case = $this->getRecord();
        $case->loadMissing(['studentProfile', 'admissionApplication']);
        $timetable = PublishedTimetableVersion::query()->where('term_id', $case->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)->latest('version')->first();
        if ($timetable === null) {
            return $this->classChoiceContext = ['sections' => collect(), 'meetings' => collect()];
        }
        $meetings = PublishedTimetableMeeting::query()->with('room')
            ->where('published_timetable_version_id', $timetable->id)->orderBy('day_of_week')->orderBy('starts_at')->get();
        $recurringSectionIds = $meetings->pluck('section_id')->unique();
        $sections = Section::query()->with('termOffering.curriculumEntry.courseSpecification.course')
            ->where('state', Section::StateOpen)
            ->whereHas('termOffering', fn (Builder $query) => $query->where('term_id', $case->term_id)
                ->where('state', TermOffering::StateScheduled)
                ->whereHas('curriculumEntry.curriculumVersion', fn (Builder $query) => $query
                    ->where('program_id', $case->studentProfile?->program_id ?? $case->admissionApplication?->program_id)
                    ->where('state', CurriculumVersion::StateActive)
                    ->when($case->studentProfile?->curriculum_version_id, fn (Builder $query, int $id) => $query->whereKey($id))))
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereIn('id', $recurringSectionIds)
                    ->whereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $query) => $query->where('scheduling_treatment', CourseSpecification::SchedulingRecurring)))
                ->orWhere(fn (Builder $query) => $query->whereNotIn('id', $recurringSectionIds)
                    ->whereHas('termOffering.curriculumEntry.courseSpecification', fn (Builder $query) => $query->where('scheduling_treatment', CourseSpecification::SchedulingExternallyArranged))))
            ->orderBy('code')->get();

        return $this->classChoiceContext = ['sections' => $sections, 'meetings' => $meetings->groupBy('section_id')];
    }

    /** @return array<int, string> */
    private function sectionDescriptions(): array
    {
        $choices = $this->classChoices();

        return $choices['sections']->mapWithKeys(function (Section $section) use ($choices): array {
            $units = (float) $section->termOffering?->courseSpecification()?->credit_units;
            $schedule = $choices['meetings']->get($section->id, collect())->map(function (PublishedTimetableMeeting $meeting): string {
                $day = match ((int) $meeting->day_of_week) {
                    1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                    5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday', default => 'Day not recorded',
                };

                return $day.' '.CarbonImmutable::parse($meeting->starts_at)->format('g:i A').'–'.CarbonImmutable::parse($meeting->ends_at)->format('g:i A')
                    .' · '.($meeting->room?->name ?? $meeting->location_label ?? 'Room not recorded');
            })->implode('; ');

            return [$section->id => $units.' units · '.($schedule !== '' ? $schedule : 'Externally arranged · no recurring meeting')];
        })->all();
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
        return $this->getRecord()->selection_basis === Enrollment::SelectionIndividuallyAdvised
            && $this->classChoices()['sections']->whereIn('id', $sectionIds)
                ->map(fn (Section $section): string => collect([
                    $section->termOffering?->curriculumEntry?->year_level,
                    $section->termOffering?->curriculumEntry?->term_label,
                    $section->termOffering?->curriculumEntry?->term_type,
                ])->implode('|'))->unique()->count() > 1;
    }

    /** @param list<int|string> $sectionIds
     * @return array<string, string>
     */
    private function curriculumPositionOptions(array $sectionIds): array
    {
        $curriculumIds = $this->classChoices()['sections']->whereIn('id', $sectionIds)
            ->pluck('termOffering.curriculumEntry.curriculum_version_id')->unique();

        return $curriculumIds->count() === 1
            ? app(StudentUnitLoadService::class)->positionOptions($this->getRecord(), (int) $curriculumIds->first())
            : [];
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
        if ($proposal?->state !== RegistrationProposalVersion::StateDraft || data_get($proposal->source_snapshot, 'unit_load.requires_graduating_overload') !== true) {
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
                ->mapWithKeys(fn (array $row): array => [$row['id'] => $row['label'].' · PHP '.number_format((float) $row['available_balance'], 2).' available'])->all() : [];
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
            $coverage->id => Str::headline($coverage->category).' · '.$coverage->safe_source_description.' · PHP '.number_format((float) $coverage->amount, 2),
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
                $evidence->id => "v{$evidence->version} — {$evidence->original_name} — PHP {$evidence->claimed_amount}",
            ])->all() ?? [];
    }

    /** @return array<int, string> */
    private function recoverablePayMongoAttemptOptions(): array
    {
        return $this->payMongoAttemptOptions([PaymentAttempt::StatusPending], requireCheckout: true);
    }

    /** @return array<int, string> */
    private function activePayMongoAttemptOptions(): array
    {
        return $this->payMongoAttemptOptions(PaymentAttempt::ActiveStatuses);
    }

    /**
     * @param  list<string>  $statuses
     * @return array<int, string>
     */
    private function payMongoAttemptOptions(array $statuses, bool $requireCheckout = false): array
    {
        $account = $this->getRecord()->termAccount;

        if ($account === null) {
            return [];
        }

        return $account->paymentAttempts()
            ->with('obligations.assessmentObligation')
            ->where('provider', 'paymongo')
            ->whereIn('status', $statuses)
            ->when($requireCheckout, fn (Builder $query): Builder => $query->whereNotNull('provider_checkout_id'))
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(function (PaymentAttempt $attempt): array {
                $targets = $attempt->obligations
                    ->map(fn ($target): string => (string) $target->assessmentObligation?->label)
                    ->filter()
                    ->implode(', ');

                return [
                    $attempt->id => collect([
                        'Attempt #'.$attempt->id,
                        'PHP '.$attempt->amount,
                        $attempt->status,
                        $targets,
                        $this->maskedProviderReference($attempt->provider_checkout_id),
                    ])->filter()->implode(' — '),
                ];
            })
            ->all();
    }

    /** @return array<int, string> */
    private function recoveredPayMongoEventOptions(): array
    {
        return $this->payMongoEventOptions(
            status: OperationalEvent::StatusReviewRequired,
            channel: OperationalEvent::ChannelProviderApi,
            eventType: 'checkout_session.payment.recovered',
        );
    }

    /** @return array<int, string> */
    private function failedPayMongoEventOptions(): array
    {
        return $this->payMongoEventOptions(
            status: OperationalEvent::StatusFailed,
            channel: OperationalEvent::ChannelWebhook,
        );
    }

    /** @return array<int, string> */
    private function confirmablePayMongoEventOptions(): array
    {
        return $this->payMongoEventOptions(
            status: OperationalEvent::StatusReviewRequired,
            channel: OperationalEvent::ChannelWebhook,
            includeReasons: ['missing_tala_reference', 'reference_mismatch'],
        );
    }

    /** @return array<int, string> */
    private function rejectablePayMongoEventOptions(): array
    {
        return $this->payMongoEventOptions(
            status: OperationalEvent::StatusReviewRequired,
            channel: OperationalEvent::ChannelWebhook,
            excludeReasons: ['refund_or_reversal', 'unknown_refund_payment'],
        );
    }

    /** @return array<int, string> */
    private function unknownPayMongoEventOptions(): array
    {
        return OperationalEvent::query()
            ->where('integration', OperationalEvent::IntegrationPayMongo)
            ->where('channel', OperationalEvent::ChannelWebhook)
            ->where('status', OperationalEvent::StatusReviewRequired)
            ->where('diagnostics->reason', 'unknown_reference')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (OperationalEvent $event): array => [
                $event->id => 'Event #'.$event->id.' — unknown reference — '.$event->event_type,
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function payMongoEventOptions(
        string $status,
        string $channel,
        ?string $eventType = null,
        ?array $includeReasons = null,
        ?array $excludeReasons = null,
    ): array {
        $account = $this->getRecord()->termAccount;

        if ($account === null) {
            return [];
        }

        $attemptIds = $account->paymentAttempts()->pluck('id');
        $paymentIds = $account->payments()->pluck('id');

        return OperationalEvent::query()
            ->where('integration', OperationalEvent::IntegrationPayMongo)
            ->where('channel', $channel)
            ->where('status', $status)
            ->when($eventType !== null, fn (Builder $query): Builder => $query->where('event_type', $eventType))
            ->when($includeReasons !== null, fn (Builder $query): Builder => $query->whereIn('diagnostics->reason', $includeReasons))
            ->when($excludeReasons !== null, fn (Builder $query): Builder => $query->whereNotIn('diagnostics->reason', $excludeReasons))
            ->where(function (Builder $query) use ($attemptIds, $paymentIds): void {
                $query->where(function (Builder $query) use ($attemptIds): void {
                    $query->where('related_record_type', PaymentAttempt::class)
                        ->whereIn('related_record_id', $attemptIds);
                })->orWhere(function (Builder $query) use ($paymentIds): void {
                    $query->where('related_record_type', Payment::class)
                        ->whereIn('related_record_id', $paymentIds);
                });
            })
            ->orderByDesc('id')
            ->get()
            ->mapWithKeys(function (OperationalEvent $event): array {
                $reason = data_get($event->diagnostics, 'reason', 'provider evidence requires review');

                return [
                    $event->id => 'Event #'.$event->id.' — '.str((string) $reason)->replace('_', ' ')->headline().' — '.$event->event_type,
                ];
            })
            ->all();
    }

    private function maskedProviderReference(?string $reference): ?string
    {
        if (blank($reference)) {
            return null;
        }

        $reference = (string) $reference;

        return 'Provider …'.substr($reference, -6);
    }

    private function webhookEvents(): Collection
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleAccounting), 403);
        $account = $this->getRecord()->termAccount;

        return OperationalEvent::query()->where('integration', OperationalEvent::IntegrationPayMongo)
            ->where('channel', OperationalEvent::ChannelWebhook)->whereIn('status', [OperationalEvent::StatusReviewRequired, OperationalEvent::StatusFailed])
            ->where(function (Builder $query) use ($account): void {
                $query->where(fn (Builder $query): Builder => $query->where('related_record_type', PaymentAttempt::class)->whereIn('related_record_id', $account?->paymentAttempts()->pluck('id') ?? []))
                    ->orWhere(fn (Builder $query): Builder => $query->where('related_record_type', Payment::class)->whereIn('related_record_id', $account?->payments()->pluck('id') ?? []));
                if (($this->enrollmentView === 'webhook' || $this->selectedOnlineAttempt()?->provider_checkout_id === null) && $this->activePayMongoAttemptOptions() !== []) {
                    $query->orWhere(fn (Builder $query): Builder => $query->whereNull('related_record_type')->whereNull('related_record_id')
                        ->where('status', OperationalEvent::StatusReviewRequired)->where('diagnostics->reason', 'unknown_reference'));
                }
            })->latest('occurred_at')->latest('id')->get()->filter(function (OperationalEvent $event) use ($account): bool {
                if ($event->related_record_type !== null) {
                    return true;
                }
                $raw = DB::table('webhook_calls')->where('id', data_get($event->diagnostics, 'latest_webhook_call_id', data_get($event->diagnostics, 'webhook_call_id')))->where('name', 'paymongo')->value('payload');
                try {
                    $context = PayMongoWebhookEvent::fromRawBody((string) $raw)->paymentContext();
                } catch (\InvalidArgumentException) {
                    return false;
                }

                return ($account?->paymentAttempts()->where('provider', 'paymongo')->whereIn('status', PaymentAttempt::ActiveStatuses)->get() ?? collect())
                    ->contains(fn (PaymentAttempt $attempt): bool => $this->webhookContextMatchesAttempt($context, $attempt));
            })->values();
    }

    /** @param array<string, mixed> $context */
    private function webhookContextMatchesAttempt(array $context, PaymentAttempt $attempt): bool
    {
        return filled($context['checkout_session_id'] ?? $context['payment_intent_id'] ?? $context['tala_reference'] ?? null)
            && (($context['checkout_session_id'] ?? null) === null || $attempt->provider_checkout_id === null || $context['checkout_session_id'] === $attempt->provider_checkout_id)
            && (($context['payment_intent_id'] ?? null) === null || $attempt->provider_intent_id === null || $context['payment_intent_id'] === $attempt->provider_intent_id)
            && (($context['tala_reference'] ?? null) === null || $context['tala_reference'] === $attempt->internal_reference)
            && (($context['term_account_id'] ?? null) === null || (string) $context['term_account_id'] === (string) $attempt->term_account_id)
            && (($context['assessment_version'] ?? null) === null || (string) $context['assessment_version'] === (string) $attempt->assessment_version)
            && (($context['snapshot_checksum'] ?? null) === null || $context['snapshot_checksum'] === $attempt->snapshot_checksum);
    }

    private function selectedWebhookEvent(): ?OperationalEvent
    {
        $events = $this->webhookEvents();

        return $this->webhookEventId !== null ? $events->firstWhere('id', $this->webhookEventId) : $events->first();
    }

    private function webhookAttempt(): ?PaymentAttempt
    {
        $event = $this->selectedWebhookEvent();
        $account = $this->getRecord()->termAccount;
        $attemptId = match ($event?->related_record_type) {
            PaymentAttempt::class => $event->related_record_id,
            Payment::class => $account?->payments()->find($event->related_record_id)?->payment_attempt_id,
            default => null,
        };

        return $attemptId !== null ? $account?->paymentAttempts()->with('obligations.assessmentObligation')->find($attemptId) : $this->selectedOnlineAttempt();
    }

    private function webhookSourceFingerprint(): string
    {
        $event = $this->selectedWebhookEvent();
        $callId = data_get($event?->diagnostics, 'latest_webhook_call_id', data_get($event?->diagnostics, 'webhook_call_id'));

        return hash('sha256', json_encode([$event?->only(['id', 'status', 'related_record_type', 'related_record_id', 'diagnostics', 'payload']),
            DB::table('webhook_calls')->where('id', $callId)->value('payload'),
            $this->webhookAttempt()?->only(['id', 'term_account_id', 'assessment_id', 'assessment_version', 'snapshot_checksum', 'status', 'provider_checkout_id', 'provider_intent_id', 'internal_reference', 'amount'])], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function webhookPaymentViewData(): array
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleAccounting), 403);
        $event = $this->selectedWebhookEvent();
        $attempt = $this->webhookAttempt();
        $context = [];
        $sourceAvailable = false;
        $sourceModeMatches = false;
        $sourceCurrent = false;
        $reason = data_get($event?->diagnostics, 'reason');
        $callId = data_get($event?->diagnostics, 'latest_webhook_call_id', data_get($event?->diagnostics, 'webhook_call_id'));
        $raw = DB::table('webhook_calls')->where('id', $callId)->where('name', 'paymongo')->value('payload');
        try {
            if (is_string($raw)) {
                $source = PayMongoWebhookEvent::fromRawBody($raw);
                $context = $source->paymentContext();
                $sourceModeMatches = $source->livemode === (bool) config('tala_integrations.payments.paymongo.livemode', false);
                $sourceAvailable = $source->eventId === $event?->external_id && $source->eventType === $event?->event_type
                    && $source->livemode === (bool) data_get($event?->payload, 'livemode')
                    && preg_match('/\A[a-f0-9]{64}\z/', (string) data_get($event?->diagnostics, 'payload_sha256')) === 1;
            }
            if ($attempt !== null) {
                $snapshot = app(ExactDuePaymentSnapshotService::class)->forAccount($this->getRecord()->termAccount);
                $sourceCurrent = $snapshot['assessment_id'] === $attempt->assessment_id && hash_equals($snapshot['checksum'], (string) $attempt->snapshot_checksum);
            }
        } catch (\InvalidArgumentException|PaymentAttemptSnapshotException) {
            $sourceCurrent = false;
        }
        $paidPhp = $sourceAvailable && ($context['status'] ?? null) === 'paid' && ($context['currency'] ?? null) === 'PHP'
            && is_int($context['amount_centavos'] ?? null) && $context['amount_centavos'] > 0
            && filled($context['provider_reference'] ?? null) && ($context['is_disputed'] ?? true) === false && ($context['has_refunds'] ?? true) === false
            && $sourceModeMatches;
        $claim = $event?->related_record_type === Payment::class ? $this->getRecord()->termAccount?->payments()->find($event->related_record_id) : null;
        $claimUnposted = $claim === null || ($claim->evidence_status !== 'verified' && $claim->ledgerEntry === null);
        $matches = $attempt !== null && $paidPhp && $context['amount_centavos'] === app(DecimalMoney::class)->toCents($attempt->amount)
            && (($context['checkout_session_id'] ?? null) === null || $context['checkout_session_id'] === $attempt->provider_checkout_id)
            && (($context['payment_intent_id'] ?? null) === null || $attempt->provider_intent_id === null || $context['payment_intent_id'] === $attempt->provider_intent_id);

        return ['case' => $this->getRecord(), 'webhookEvent' => $event, 'webhookContext' => $context, 'attempt' => $attempt,
            'webhookReason' => $reason, 'sourceAvailable' => $sourceAvailable, 'sourceCurrent' => $sourceCurrent,
            'canConfirmWebhook' => $matches && $sourceCurrent && $claimUnposted && in_array($reason, ['missing_tala_reference', 'reference_mismatch'], true)
                && $event?->related_record_type === Payment::class,
            'canRejectWebhook' => $sourceAvailable && $claimUnposted && $event?->status === OperationalEvent::StatusReviewRequired && ! in_array($reason, ['refund_or_reversal', 'unknown_refund_payment'], true),
            'canRetryWebhook' => $sourceAvailable && $event?->status === OperationalEvent::StatusFailed && $reason === 'processing_failed',
            'canLinkWebhook' => $sourceAvailable && $paidPhp && $reason === 'unknown_reference' && $attempt !== null && $this->webhookContextMatchesAttempt($context, $attempt),
            'webhookChoices' => $this->webhookEvents(), 'checkoutChoices' => $reason === 'unknown_reference' ? ($this->getRecord()->termAccount?->paymentAttempts()->where('provider', 'paymongo')->whereIn('status', PaymentAttempt::ActiveStatuses)->get()->filter(fn (PaymentAttempt $choice): bool => $this->webhookContextMatchesAttempt($context, $choice)) ?? collect()) : collect(),
            'currentTargets' => collect($this->accountingViewData()['position']['obligations'] ?? [])->whereIn('id', $attempt?->obligations->pluck('assessment_obligation_id') ?? [])];
    }

    private function webhookBoundFields(): array
    {
        return [Hidden::make('event_id')->required()->in(fn (): array => [$this->selectedWebhookEvent()?->id]),
            Hidden::make('reviewed_source')->required()->in(fn (): array => [$this->webhookSourceFingerprint()])
                ->validationMessages(['in' => 'The payment source changed. Close this form and review the current account.'])];
    }

    /** @param array<string, mixed> $data */
    private function decideWebhook(array $data, string $decision): void
    {
        DB::transaction(function () use ($data, $decision): void {
            $event = $this->selectedWebhookEvent();
            abort_unless($event !== null && $event->id === (int) $data['event_id'], 404);
            OperationalEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($attempt = $this->webhookAttempt()) {
                PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            }
            DB::table('webhook_calls')->where('id', data_get($event->diagnostics, 'latest_webhook_call_id', data_get($event->diagnostics, 'webhook_call_id')))->lockForUpdate()->first();
            if (! hash_equals($this->webhookSourceFingerprint(), (string) $data['reviewed_source'])) {
                throw ValidationException::withMessages(['reviewed_source' => 'The reviewed source changed. Refresh before deciding.']);
            }
            $service = app(PayMongoReconciliationService::class);
            match ($decision) {
                'confirm' => $service->confirm($event->id, (string) $data['reason'], $this->actor()),
                'reject' => $service->reject($event->id, (string) $data['reason'], $this->actor()),
                'retry' => $service->reprocess($event->id, (string) $data['reason'], $this->actor()),
                'link' => $service->linkAndReprocess($event->id, (int) $this->webhookAttempt()?->id, (string) $data['reason'], $this->actor()),
            };
        });
    }

    public function selectedOnlineAttempt(): ?PaymentAttempt
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleAccounting), 403);
        $query = $this->getRecord()->termAccount?->paymentAttempts()->with(['obligations.assessmentObligation', 'assessment'])->whereIn('status', PaymentAttempt::ActiveStatuses);

        return $this->onlineAttemptId !== null ? $query?->find($this->onlineAttemptId)
            : $query?->whereIn('status', PaymentAttempt::ActiveStatuses)->latest('id')->first();
    }

    private function recoveredOnlineEvents(): Collection
    {
        $attempt = $this->selectedOnlineAttempt();

        return OperationalEvent::query()->where('event_domain', OperationalEvent::DomainIntegration)
            ->where('integration', OperationalEvent::IntegrationPayMongo)->where('channel', OperationalEvent::ChannelProviderApi)
            ->where('event_type', 'checkout_session.payment.recovered')->where('status', OperationalEvent::StatusReviewRequired)
            ->where('related_record_type', PaymentAttempt::class)->where('related_record_id', $attempt?->id ?? 0)->latest('id')->get();
    }

    private function selectedRecoveredOnlineEvent(): ?OperationalEvent
    {
        $events = $this->recoveredOnlineEvents();

        return $this->onlineEventId !== null ? $events->firstWhere('id', $this->onlineEventId) : $events->first();
    }

    private function canRetrieveSelectedCheckout(): bool
    {
        $attempt = $this->selectedOnlineAttempt();

        return $attempt !== null && $attempt->provider === 'paymongo' && filled($attempt->provider_checkout_id)
            && in_array($attempt->status, PaymentAttempt::ActiveStatuses, true)
            && filled($attempt->snapshot_checksum) && $attempt->assessment?->state === Assessment::StateActive
            && $attempt->assessment->term_account_id === $attempt->term_account_id
            && $attempt->student_profile_id === $this->getRecord()->student_profile_id
            && $this->selectedRecoveredOnlineEvent() === null;
    }

    private function onlineSourceFingerprint(): string
    {
        $attempt = $this->selectedOnlineAttempt();
        $event = $this->selectedRecoveredOnlineEvent();

        return hash('sha256', json_encode([$attempt?->only(['id', 'term_account_id', 'assessment_id', 'assessment_version', 'snapshot_checksum',
            'provider', 'provider_checkout_id', 'provider_intent_id', 'status', 'amount']), $attempt?->obligations->map->only(['assessment_obligation_id', 'sequence', 'amount'])->all(),
            $event?->only(['id', 'status', 'related_record_type', 'related_record_id', 'payload'])], JSON_THROW_ON_ERROR));
    }

    private function onlineCheckoutSnapshot(): array
    {
        $account = $this->getRecord()->termAccount;
        $attempt = $this->selectedOnlineAttempt();

        if ($account === null || $attempt === null) {
            return [];
        }

        return [
            'account_id' => (int) $account->id,
            'attempt_id' => (int) $attempt->id,
            'event_id' => $this->selectedRecoveredOnlineEvent()?->id,
            'fingerprint' => $this->onlineSourceFingerprint(),
        ];
    }

    private function assertReviewedOnlineCheckout(?int $eventId = null): int
    {
        $snapshot = $this->reviewedOnlineCheckout;
        $current = $this->onlineCheckoutSnapshot();

        if ($snapshot === [] || $snapshot !== $current || ($eventId !== null && $snapshot['event_id'] !== $eventId)) {
            throw ValidationException::withMessages(['payment' => 'The reviewed payment source changed. Refresh before continuing.']);
        }

        return $snapshot['attempt_id'];
    }

    public function onlinePaymentHistory(): Collection
    {
        abort_unless($this->actor()->hasRole(User::StaffRoleAccounting), 403);
        $account = $this->getRecord()->termAccount;

        return OperationalEvent::query()->where('integration', OperationalEvent::IntegrationPayMongo)
            ->whereIn('channel', [OperationalEvent::ChannelProviderApi, OperationalEvent::ChannelWebhook])
            ->where(function (Builder $query) use ($account): void {
                $query->where(fn (Builder $query): Builder => $query->where('related_record_type', PaymentAttempt::class)->whereIn('related_record_id', $account?->paymentAttempts()->pluck('id') ?? []))
                    ->orWhere(fn (Builder $query): Builder => $query->where('related_record_type', Payment::class)->whereIn('related_record_id', $account?->payments()->pluck('id') ?? []));
            })->latest('occurred_at')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function decideRecoveredOnlinePayment(array $data, bool $confirm): void
    {
        DB::transaction(function () use ($data, $confirm): void {
            $attemptId = $this->assertReviewedOnlineCheckout((int) $data['event_id']);
            PaymentAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();
            $event = $this->selectedRecoveredOnlineEvent();
            abort_unless($event !== null && $event->id === (int) $data['event_id'], 404);
            OperationalEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            if ($this->onlineCheckoutSnapshot() !== $this->reviewedOnlineCheckout) {
                throw ValidationException::withMessages(['payment' => 'The reviewed payment source changed. Refresh before deciding.']);
            }
            $service = app(PayMongoCheckoutRecoveryService::class);
            if ($confirm) {
                $service->confirm($event->id, null, $this->actor());
            } else {
                $service->reject($event->id, (string) $data['reason'], $this->actor());
            }
        });
    }

    /** @return array<string, mixed> */
    public function onlinePaymentViewData(): array
    {
        $attempt = $this->selectedOnlineAttempt();
        $event = $this->selectedRecoveredOnlineEvent();
        $payment = data_get($event?->payload, 'payment', []);
        $payment = is_array($payment) ? $payment : [];
        $sourceCurrent = false;
        if ($attempt !== null) {
            try {
                $snapshot = app(ExactDuePaymentSnapshotService::class)->forAccount($this->getRecord()->termAccount);
                $stored = $attempt->obligations->map(fn ($target): array => ['id' => (int) $target->assessment_obligation_id,
                    'sequence' => (int) $target->sequence, 'amount' => $target->amount])->all();
                $expected = collect($snapshot['obligations'])->map(fn (array $target): array => ['id' => $target['id'], 'sequence' => $target['sequence'], 'amount' => $target['amount']])->all();
                $sourceCurrent = $snapshot['assessment_id'] === $attempt->assessment_id && $snapshot['assessment_version'] === $attempt->assessment_version
                    && hash_equals($snapshot['checksum'], (string) $attempt->snapshot_checksum) && $stored === $expected;
            } catch (PaymentAttemptSnapshotException) {
                $sourceCurrent = false;
            }
        }
        $resultMatches = $event !== null && $attempt !== null && $attempt->provider === 'paymongo'
            && in_array($attempt->status, PaymentAttempt::ActiveStatuses, true)
            && data_get($event->payload, 'checkout_session_id') === $attempt->provider_checkout_id
            && data_get($event->payload, 'reference_number') === $attempt->internal_reference
            && data_get($event->payload, 'payment_count') === 1 && filled($payment['id'] ?? null)
            && ($payment['status'] ?? null) === 'paid' && is_int($payment['amount_centavos'] ?? null)
            && $payment['amount_centavos'] === app(DecimalMoney::class)->toCents($attempt->amount)
            && ($payment['currency'] ?? null) === 'PHP' && ($payment['livemode'] ?? null) === (bool) config('tala_integrations.payments.paymongo.livemode', false)
            && filled($payment['payment_intent_id'] ?? null)
            && ($attempt->provider_intent_id === null || $attempt->provider_intent_id === $payment['payment_intent_id'])
            && ($payment['disputed'] ?? true) === false && ($payment['has_refunds'] ?? true) === false
            && (($payment['paid_at'] ?? null) === null || is_int($payment['paid_at']));

        return ['case' => $this->getRecord(), 'attempt' => $attempt, 'onlineEvent' => $event, 'providerPayment' => $payment,
            'sourceCurrent' => $sourceCurrent, 'resultMatches' => $resultMatches, 'canConfirm' => $sourceCurrent && $resultMatches,
            'canRetrieve' => $this->canRetrieveSelectedCheckout(), 'onlineEvents' => $this->recoveredOnlineEvents(),
            'onlineAttempts' => $this->getRecord()->termAccount?->paymentAttempts()->whereIn('status', PaymentAttempt::ActiveStatuses)->latest('id')->get() ?? collect(),
            'currentTargets' => collect($this->accountingViewData()['position']['obligations'] ?? [])->whereIn('id', $attempt?->obligations->pluck('assessment_obligation_id') ?? [])];
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
        $proofs = $this->getRecord()->termAccount?->paymentEvidenceVersions();
        if ($this->paymentProofId !== null) {
            return $proofs?->where('state', PaymentEvidenceVersion::StateSubmitted)->find($this->paymentProofId);
        }

        return $proofs?->where('state', PaymentEvidenceVersion::StateSubmitted)->latest('submitted_at')->latest('id')->first();
    }

    private function selectedProofField(): Hidden
    {
        return Hidden::make('evidence_id')->required()->in(fn (): array => [$this->selectedPaymentProof()?->id]);
    }

    private function boundSubmittedProof(int $proofId): PaymentEvidenceVersion
    {
        if ($this->selectedPaymentProof()?->id !== $proofId) {
            throw ValidationException::withMessages(['evidence_id' => 'This proof is no longer selected or awaiting review. Refresh the account.']);
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

        return ['proof' => $proof, 'case' => $this->getRecord(), 'targets' => $targets,
            'sourceCurrent' => $sourceCurrent, 'allocation' => $allocation, 'allocationError' => $allocationError,
            'pendingProofs' => $context['account']?->paymentEvidenceVersions->where('state', PaymentEvidenceVersion::StateSubmitted)->sortByDesc('submitted_at') ?? collect()];
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
        Notification::make()->title($title)->body($exception->getMessage())->danger()->send();
    }
}
