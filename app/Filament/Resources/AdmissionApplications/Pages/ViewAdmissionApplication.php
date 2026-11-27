<?php

namespace App\Filament\Resources\AdmissionApplications\Pages;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Actions\Admissions\AdmissionPaperChecklist;
use App\Actions\Admissions\AdmissionReviewGuard;
use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Admissions\ExtendAdmissionDeadline;
use App\Actions\Admissions\RecordAdmissionOutcome;
use App\Actions\Admissions\RecordRegistrarEnrollmentClearance;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\ResolveAdmissionIdentity;
use App\Actions\Admissions\ReviewPreliminaryEvidence;
use App\Actions\Admissions\SendAdmissionProcessRemark;
use App\Actions\Calendar\Exceptions\CalendarGateViolation;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Filament\Support\AdmissionPaperFields;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\ApplicationCorrectionRequest;
use App\Models\DocumentEvidence;
use App\Models\Enrollment;
use App\Models\IdentityMatchReview;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PreliminaryEvidenceReview;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\RegistrationProposalVersion;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\AdmissionDeadlineStatus;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Size;
use Filament\Support\Enums\Width;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ViewAdmissionApplication extends ViewRecord
{
    private const AdmittedDecisionReason = 'Required evidence is acceptable';

    private const AdmittedApplicantExplanation = 'Your application has been admitted. Admissions will contact you about the next steps.';

    protected static string $resource = AdmissionApplicationResource::class;

    #[Url]
    public ?string $queue = null;

    /** @var array<string, string|null> */
    public array $processRemarks = [];

    /** @var array{status: string|null, kind: string|null, deadline: CarbonInterface|null, reason: string|null}|null */
    private ?array $deadlineStatusCache = null;

    private ?Enrollment $currentEnrollmentCache = null;

    public function queueContext(): ?string
    {
        return in_array($this->queue, ['needs_review', 'waiting_for_applicant', 'official_credentials', 'ready_for_enrollment', 'history'], true) ? $this->queue : null;
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (! in_array($this->queue, ['needs_review', 'waiting_for_applicant', 'official_credentials', 'ready_for_enrollment', 'history'], true)) {
            $this->queue = null;
        }
    }

    public function getTitle(): string
    {
        return 'Review application — '.AdmissionApplicationResource::getRecordTitle($this->getRecord());
    }

    public function getBreadcrumb(): string
    {
        return 'Review application';
    }

    public function getBreadcrumbs(): array
    {
        $original = AdmissionApplicationResource::getUrl();
        $queue = $this->queueContext() ?? request()->query('queue');
        $queue = in_array($queue, ['needs_review', 'waiting_for_applicant', 'official_credentials', 'ready_for_enrollment', 'history'], true) ? $queue : null;
        $target = $queue === null ? $original : ListAdmissionApplications::getUrl(['tab' => $queue]);

        return collect(parent::getBreadcrumbs())->mapWithKeys(fn (string $label, int|string $url): array => [
            $url === $original ? $target : $url => $url === $original ? 'Application queue' : $label,
        ])->all();
    }

    /** Actions the Process evaluation steps open; the page header does not repeat them. */
    private const StepActionNames = ['startReview', 'reviewApplication', 'recordEnrollmentClearance', 'updateFollowedPapers', 'startEnrollment', 'openEnrollment', 'reviewReadiness'];

    /**
     * Every admission action on this page, flat, before header placement.
     *
     * @return list<Action>
     */
    private function admissionActions(): array
    {
        $actions = [
            Action::make('startReview')
                ->label('Start review')->icon('heroicon-o-play')->color('primary')
                ->modalSubmitActionLabel('Start review')
                ->requiresConfirmation()
                ->modalDescription('Lock this submission for review. Any later changes will require a correction request.')
                ->schema([Hidden::make('expected_submission_version_id')->default(fn (): ?int => $this->application()->current_submission_version_id)])
                ->visible(fn (): bool => (bool) auth()->user()?->can('review', $this->application()) && $this->application()->allowsSubmittedEdit() && ! $this->isClosed())
                ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                    app(AdmissionReviewGuard::class)->start($this->application(), $actor, (int) $data['expected_submission_version_id']);
                }, 'Review started', $action)),
            Action::make('manageCorrectionBoundary')
                ->label('Extend correction boundary')
                ->icon('heroicon-o-calendar-days')
                ->color('warning')
                ->url(fn (): string => AdmissionCycleResource::getUrl('view', [
                    'record' => $this->application()->admissionCycle,
                ]))
                ->visible(fn (): bool => $this->canReview()
                    && $this->canManageAdmissionSetup()
                    && $this->application()->application_state === AdmissionApplication::StateSubmitted
                    && ! $this->correctionIssuanceIsOpen()),
            Action::make('correctionBoundaryRecovery')
                ->label('Correction issuance closed')
                ->icon('heroicon-o-information-circle')
                ->color('gray')
                ->action(fn (): mixed => Notification::make()
                    ->title('Authorized extension required')
                    ->body('Ask the Registrar owner with admission-setup authority to extend the correction boundary. Existing review, decision, and clearance work remains available.')
                    ->warning()
                    ->send())
                ->visible(fn (): bool => $this->canReview()
                    && ! $this->canManageAdmissionSetup()
                    && $this->application()->application_state === AdmissionApplication::StateSubmitted
                    && ! $this->correctionIssuanceIsOpen()),
            ActionGroup::make([
                Action::make('reviewReadiness')
                    ->label('Check enrollment readiness')
                    ->icon('heroicon-o-check-circle')
                    ->modalHeading('Admissions ready for enrollment')
                    ->modal()
                    ->modalDescription('Admission decision, identity review, and enrollment clearance are complete. This applicant is ready for enrollment.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->visible(fn (): bool => $this->application()->application_state === AdmissionApplication::StateAdmitted
                        && app(ReadyApplicantProjectionQuery::class)->forApplication($this->application())['ready']),
                $this->reviewApplicationAction(),
                Action::make('resolveIdentity')
                    ->label('Resolve identity warning')
                    ->modalSubmitActionLabel('Record identity resolution')
                    ->icon('heroicon-o-identification')
                    ->modalDescription(fn (): string => 'Record the identity check result for '.$this->application()->application_reference.'.')
                    ->schema([
                        Select::make('identity_match_review_id')
                            ->label('Private warning')
                            ->options($this->identityWarningOptions())
                            ->required(),
                        Hidden::make('expected_submission_version_id')->default(fn () => $this->application()->current_submission_version_id),
                        Select::make('outcome')
                            ->options([
                                IdentityMatchReview::OutcomeSamePerson => 'Same person',
                                IdentityMatchReview::OutcomeDifferentPerson => 'Different person',
                                IdentityMatchReview::OutcomeCorrectedIdentifier => 'Corrected identifier',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('evidence_reference')
                            ->label('Checked record reference')
                            ->helperText('Identify the official record or authorized check supporting this identity result. Enter a source reference, not private document contents.')
                            ->placeholder('For example: school record check or verification case reference')
                            ->required()->maxLength(255),
                        TextInput::make('corrected_identifier')
                            ->maxLength(64)
                            ->required(fn (Get $get): bool => $get('outcome') === IdentityMatchReview::OutcomeCorrectedIdentifier)
                            ->visible(fn (Get $get): bool => $get('outcome') === IdentityMatchReview::OutcomeCorrectedIdentifier),
                    ])
                    ->visible(fn (): bool => $this->canResolveIdentity() && $this->identityWarningOptions() !== [])
                    ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                        $review = $this->application()->identityMatchReviews->findOrFail((int) $data['identity_match_review_id']);
                        app(ResolveAdmissionIdentity::class)->execute(
                            $review,
                            $actor,
                            (string) $data['outcome'],
                            (string) $data['evidence_reference'],
                            filled($data['corrected_identifier'] ?? null) ? (string) $data['corrected_identifier'] : null,
                            (int) $data['expected_submission_version_id'],
                        );
                    }, 'Identity warning resolved', $action)),
                Action::make('recordEnrollmentClearance')
                    ->label(fn (): string => $this->application()->enrollmentClearances()->whereDoesntHave('successor')->exists() ? 'Update enrollment clearance' : 'Check enrollment papers')
                    ->modalHeading(fn (): string => $this->application()->enrollmentClearances()->whereDoesntHave('successor')->exists() ? 'Update enrollment clearance' : 'Enrollment clearance')
                    // Clearing starts the enrollment in the same step whenever the term allows it (#99 Scope 10).
                    ->modalSubmitActionLabel(fn (): string => $this->canStartEnrollment(requireReady: false) ? 'Record clearance and start enrollment' : 'Record clearance')
                    ->modalSubmitAction(fn (Action $action): Action => $action->color('success'))
                    ->modalWidth(Width::TwoExtraLarge)
                    ->icon('heroicon-o-check-badge')
                    ->modalDescription(function (): string {
                        $currentClearance = $this->application()->enrollmentClearances()->whereDoesntHave('successor')->first();
                        $prefix = $currentClearance ? 'Current status: '.str($currentClearance->result)->headline().'. ' : '';

                        return $prefix.'Tick each original paper received in person. Ticked papers are tagged Passed; unticked papers are tagged To be followed, and clearance can still be recorded.';
                    })
                    ->schema(fn (): array => [
                        Hidden::make('expected_current_clearance_id')
                            ->default(fn () => $this->application()->enrollmentClearances()->whereDoesntHave('successor')->value('id')),
                        Hidden::make('expected_decision_id')
                            ->default(fn () => $this->application()->decisions()->whereDoesntHave('successor')->value('id')),
                        Hidden::make('expected_submission_version_id')
                            ->default(fn () => $this->application()->current_submission_version_id),
                        ...$this->paperChecklistFields(),
                        Checkbox::make('external_checks_confirmed')
                            ->label('I confirm the required external enrollment checks are complete.')
                            ->visible(fn (): bool => $this->clearanceChecklist() === []),
                        Textarea::make('reason')
                            ->label('Reason for replacing the previous clearance')
                            ->visible(fn (Get $get): bool => filled($get('expected_current_clearance_id')))
                            ->required(fn (Get $get): bool => filled($get('expected_current_clearance_id')))
                            ->maxLength(1500),
                    ])
                    // Gone once the papers are cleared; an Action needed clearance can still be updated (#99 Scope 10).
                    ->visible(fn (): bool => $this->canReview()
                        && $this->application()->application_state === AdmissionApplication::StateAdmitted
                        && $this->deadlineStatus()['kind'] !== AdmissionDeadlineStatus::KindRequirements
                        && ! $this->readinessIsCleared())
                    ->action(function (array $data, Action $action): void {
                        $startEnrollment = $this->canStartEnrollment(requireReady: false);

                        $this->runAction(
                            fn (User $actor) => $this->recordClearanceWithChecklist($actor, $data),
                            'Enrollment clearance recorded',
                            $action,
                        );

                        if ($startEnrollment) {
                            $this->deadlineStatusCache = null;
                            $this->startEnrollmentAndOpen(afterClearance: true);
                        }
                    }),
                // #103 Scope 7: after clearance, papers still To be followed can be ticked as Passed.
                Action::make('updateFollowedPapers')
                    ->label('Update papers')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->modalHeading('Update papers')
                    ->modalDescription('Tick the papers the Applicant has now passed. They are tagged Passed; the rest stay To be followed.')
                    ->modalSubmitActionLabel('Save papers')
                    ->modalWidth(Width::TwoExtraLarge)
                    ->schema(fn (): array => $this->paperChecklistFields(onlyFollowed: true))
                    ->visible(fn (): bool => $this->canReview()
                        && $this->application()->application_state === AdmissionApplication::StateAdmitted
                        && $this->readinessIsCleared()
                        && $this->followedPapers() !== [])
                    ->action(fn (array $data, Action $action): mixed => $this->runAction(
                        fn (User $actor) => $this->recordPassedPapers($actor, $data),
                        'Papers updated',
                        $action,
                    )),
                Action::make('startEnrollment')
                    ->label('Start enrollment')
                    ->icon('heroicon-o-user-plus')
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-user-plus')
                    ->modalHeading('Start enrollment at the Registrar desk')
                    ->modalDescription(fn (): string => 'Start enrollment for '.$this->application()->first_name.' '.$this->application()->last_name.' ('.($this->application()->term?->label ?? 'admitted term').'). You will review proposed subjects next before Accounting assesses fees.')
                    ->modalSubmitActionLabel('Start enrollment')
                    ->visible(fn (): bool => $this->canStartEnrollment())
                    ->action(fn (): mixed => $this->startEnrollmentAndOpen()),
                Action::make('openEnrollment')
                    ->label('Open enrollment')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (): ?string => ($enrollment = $this->currentEnrollment()) ? EnrollmentResource::getUrl('view', ['record' => $enrollment]) : null)
                    ->visible(fn (): bool => $this->currentEnrollment() !== null && auth()->user()?->can('view', $this->currentEnrollment())),
                Action::make('extendDeadline')
                    ->label(fn (): string => $this->deadlineStatus()['status'] === AdmissionDeadlineStatus::Expired ? 'Re-admit with a new deadline' : 'Extend deadline')
                    ->modalHeading(fn (): string => $this->deadlineStatus()['status'] === AdmissionDeadlineStatus::Expired ? 'Re-admit with a new deadline' : 'Extend deadline')
                    ->modalSubmitActionLabel('Save new deadline')
                    ->modalSubmitAction(fn (Action $action): Action => $action->disabled(! $this->deadlineWindowIsOpen()))
                    ->icon('heroicon-o-calendar-days')
                    ->modalDescription(fn (): string => $this->deadlineStatus()['status'] === AdmissionDeadlineStatus::Expired
                        ? 'This application expired ('.$this->deadlineStatus()['reason'].'). Set a new deadline to reopen it and email the applicant.'
                        : 'Set a new deadline for this applicant only. They will be emailed the updated date.')
                    ->fillForm(fn (): array => [
                        'kind' => $this->deadlineStatus()['kind']
                            ?? ($this->readinessIsCleared() ? AdmissionDeadlineStatus::KindPayment : AdmissionDeadlineStatus::KindRequirements),
                    ])
                    ->schema([
                        Radio::make('kind')
                            ->label('Deadline to move')
                            ->options([
                                AdmissionDeadlineStatus::KindRequirements => 'Physical requirements',
                                AdmissionDeadlineStatus::KindPayment => 'Enrollment payment',
                            ])
                            ->descriptions(fn (): array => [
                                AdmissionDeadlineStatus::KindRequirements => 'Current: '.($this->formatDeadline(app(AdmissionDeadlineStatus::class)->requirementsDeadline($this->application())) ?? 'not set'),
                                AdmissionDeadlineStatus::KindPayment => 'Current: '.($this->formatDeadline(app(AdmissionDeadlineStatus::class)->paymentDeadline($this->application())) ?? 'not set'),
                            ])
                            ->required(),
                        DateTimePicker::make('deadline')
                            ->label('New deadline')
                            ->timezone(config('app.display_timezone'))
                            ->displayFormat('M j, Y · g:i A')
                            ->seconds(false)
                            ->native(true)
                            ->minDate(fn (): ?string => $this->pickerDayStart(now()))
                            ->maxDate(fn (): ?string => $this->pickerDayEnd($this->application()->admissionCycle?->period_ends_at))
                            ->rule(fn (): \Closure => $this->exactWindowRule(fn (): CarbonInterface => now(), fn (): ?CarbonInterface => $this->application()->admissionCycle?->period_ends_at, 'the end of the admission period'))
                            ->prefixIcon('heroicon-o-calendar-days')
                            ->helperText(fn (): string => $this->deadlineWindowGuidance())
                            ->validationMessages([
                                'after_or_equal' => fn (): string => 'Choose a time after now ('.$this->formatDeadline(now()).').',
                                'before_or_equal' => fn (): string => 'Choose a time on or before the end of the admission period ('.$this->formatDeadline($this->application()->admissionCycle?->period_ends_at).').',
                            ])
                            ->disabled(fn (): bool => ! $this->deadlineWindowIsOpen())
                            ->required(),
                        Actions::make([
                            Action::make('openCycleDeadlines')
                                ->label('Set admission deadlines')
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->color('gray')
                                ->link()
                                ->url(fn (): ?string => $this->application()->admissionCycle
                                    ? AdmissionCycleResource::getUrl('view', ['record' => $this->application()->admissionCycle])
                                    : null)
                                ->openUrlInNewTab(),
                        ])->visible(fn (): bool => ! $this->deadlineWindowIsOpen() && $this->canManageAdmissionSetup()),
                        Textarea::make('reason')
                            ->label('Reason')
                            ->placeholder('For example: The Applicant asked for more time to get the original Form 138 from their school.')
                            ->rows(2)
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && $this->application()->application_state === AdmissionApplication::StateAdmitted)
                    ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                        app(ExtendAdmissionDeadline::class)->execute(
                            $this->application(),
                            $actor,
                            (string) $data['kind'],
                            CarbonImmutable::parse((string) $data['deadline']),
                            (string) $data['reason'],
                        );
                    }, 'Deadline saved', $action)),
                Action::make('withdraw')
                    ->label('Record withdrawal')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalWidth(Width::Large)
                    ->modalDescription('Withdraw this application from enrollment. Existing records stay in history, and reopening requires Registrar approval.')
                    ->modalSubmitActionLabel('Record withdrawal')
                    ->schema([
                        Textarea::make('reason')->label('Reason for withdrawal')
                            ->placeholder('Explain the Applicant request or school instruction being recorded.')
                            ->required()->maxLength(1000),
                        TextInput::make('authority_reference')->label('Request or approval reference')
                            ->helperText('Reference the Applicant’s withdrawal request or school approval. Your name and time are recorded automatically.')
                            ->placeholder('e.g. Withdrawal request ADM-2026-014')
                            ->required()->maxLength(255),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && ! in_array($this->application()->application_state, [
                            AdmissionApplication::StateDraft,
                            AdmissionApplication::StateWithdrawn,
                        ], true))
                    ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                        app(ChangeAdmissionApplicationLifecycle::class)->withdrawByRegistrar(
                            $this->application(),
                            $actor,
                            (string) $data['reason'],
                            (string) $data['authority_reference'],
                        );
                    }, 'Application withdrawal recorded', $action)),
                Action::make('reopen')
                    ->label('Reopen withdrawn application')
                    ->color('gray')
                    ->modalSubmitActionLabel('Reopen application')
                    ->icon('heroicon-o-arrow-path')
                    ->modalDescription(fn (): string => 'Reopen '.$this->application()->application_reference.' with recorded approval. Prior history is retained.')
                    ->schema([
                        Textarea::make('reason')->label('Reason for reopening')
                            ->placeholder('Explain why the same withdrawn application should resume.')
                            ->required()->maxLength(1000),
                        TextInput::make('authority_reference')->label('Approval to reopen')
                            ->helperText('Identify the existing school approval for reopening this withdrawn record. Earlier submissions and withdrawal history remain retained.')
                            ->placeholder('Existing approval or recorded authorization reference')
                            ->required()->maxLength(255),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && $this->application()->application_state === AdmissionApplication::StateWithdrawn)
                    ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                        app(ChangeAdmissionApplicationLifecycle::class)->reopen(
                            $this->application(),
                            $actor,
                            (string) $data['reason'],
                            (string) $data['authority_reference'],
                        );
                    }, 'Application reopened', $action)),
                Action::make('acknowledgment')
                    ->label('Open acknowledgment')
                    ->icon('heroicon-o-printer')
                    ->url(fn (): string => route('admissions.application.acknowledgment', [
                        'application' => $this->application(),
                        'version' => $this->application()->currentSubmissionVersion,
                        'queue' => $this->queueContext(),
                    ]))
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => $this->application()->currentSubmissionVersion !== null),
                Action::make('resendFailedNotification')
                    ->label('Resend failed Applicant update')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => $this->canReview()
                        && $this->failedNotification() instanceof OperationalEvent)
                    ->action(fn (Action $action): mixed => $this->runAction(function (User $actor): void {
                        $event = $this->failedNotification();
                        abort_unless($event instanceof OperationalEvent, 404);
                        app(AdmissionNotificationLedger::class)->resend($event, $actor);
                    }, 'Applicant update queued again', $action)),
            ])->label('Application actions')->icon('heroicon-o-chevron-down')->button()->color('gray'),
        ];

        $group = array_pop($actions);

        return array_values(array_filter([...$actions, ...$group->getActions()], fn (mixed $action): bool => $action instanceof Action));
    }

    /** One named action from the page's admission actions, for a Process evaluation step button. */
    private function admissionAction(string $name): Action
    {
        $action = collect($this->admissionActions())->first(fn (Action $action): bool => $action->getName() === $name);
        abort_unless($action instanceof Action, 404);

        return $action->group(null)->modalCancelAction(false);
    }

    public function startReviewAction(): Action
    {
        return $this->admissionAction('startReview');
    }

    public function recordEnrollmentClearanceAction(): Action
    {
        return $this->admissionAction('recordEnrollmentClearance');
    }

    public function updateFollowedPapersAction(): Action
    {
        return $this->admissionAction('updateFollowedPapers');
    }

    public function startEnrollmentAction(): Action
    {
        return $this->admissionAction('startEnrollment');
    }

    public function openEnrollmentAction(): Action
    {
        return $this->admissionAction('openEnrollment');
    }

    public function reviewReadinessAction(): Action
    {
        return $this->admissionAction('reviewReadiness');
    }

    protected function getHeaderActions(): array
    {
        // Step actions (review, clearance, enrollment) live on the Process evaluation steps only.
        $actions = array_values(array_filter($this->admissionActions(), fn (Action $action): bool => ! in_array($action->getName(), self::StepActionNames, true)));
        $primaryName = match ($this->application()->application_state) {
            AdmissionApplication::StateSubmitted => $this->isClosed() ? null : (! $this->application()->reviewHasStarted() ? 'startReview' : ($this->identityWarningOptions() !== []
                ? 'resolveIdentity'
                : 'reviewApplication')),
            AdmissionApplication::StateAdmitted => match (true) {
                $this->deadlineStatus()['status'] === AdmissionDeadlineStatus::Closed => null,
                $this->deadlineStatus()['status'] === AdmissionDeadlineStatus::Expired => 'extendDeadline',
                $this->canStartEnrollment() => 'startEnrollment',
                $this->currentEnrollment() !== null => 'openEnrollment',
                app(ReadyApplicantProjectionQuery::class)->forApplication($this->application())['ready'] => 'reviewReadiness',
                default => 'recordEnrollmentClearance',
            },
            AdmissionApplication::StateWithdrawn,
            AdmissionApplication::StateActionNeeded,
            AdmissionApplication::StateNotAdmitted => null,
            default => 'acknowledgment',
        };
        $secondary = [];
        $primary = null;

        foreach ($actions as $action) {
            if ($action instanceof Action && $action->getName() === $primaryName) {
                $primary = $action->group(null)->button()->color(in_array($primaryName, ['reviewReadiness', 'openEnrollment'], true) ? 'gray' : 'primary');
            } elseif ($action instanceof Action) {
                // Side-by-side buttons instead of a dropdown; review choices live on the Application screening step.
                $secondary[] = $action->group(null)->button()->outlined()->color($action->getName() === 'withdraw' ? 'danger' : 'gray');
            }
        }

        // Every modal closes with its corner X, so no footer Cancel button (#99 Scope 10).
        return array_map(
            fn (Action $action): Action => $action->modalCancelAction(false),
            array_values(array_filter([$primary, ...$secondary])),
        );
    }

    /** Scoped correction request, opened from the Application screening step's For correction button. */
    public function requestCorrectionAction(): Action
    {
        return Action::make('requestCorrection')
            ->modalCancelAction(false)
            ->label('Request corrections')
            ->modalSubmitActionLabel('Request corrections')
            ->icon('heroicon-o-pencil-square')
            ->color('warning')
            ->modalWidth('4xl')
            ->modalDescription('Select the fields or documents the applicant needs to fix and set a due date.')
            ->schema([
                Hidden::make('expected_submission_version_id')->default(fn (): ?int => $this->application()->current_submission_version_id),
                Hidden::make('expected_target_requirement_set_id'),
                Hidden::make('responsible_party')->default('Applicant'),
                Select::make('fields')
                    ->label('Application fields to reopen')
                    ->helperText('Select any submitted demographic, contact, or educational fields that require Applicant correction.')
                    ->options($this->correctableFieldOptions())
                    ->live()
                    ->multiple()
                    ->searchable()
                    ->rule(fn (Get $get): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($get): void {
                        $fields = (array) $value;
                        $evidence = (array) $get('evidence_requirements');
                        if (blank(array_filter($fields)) && blank(array_filter($evidence))) {
                            $fail('Select at least one field or evidence requirement to reopen for correction.');
                        }
                    }),
                Radio::make('target_application_path')->label('Requested Student type')
                    ->options(fn (): array => array_diff_key(AdmissionCycle::studentTypeOptions(), [$this->application()->application_path => true]))
                    ->visible(fn (Get $get): bool => in_array('application_path', (array) $get('fields'), true))
                    ->required()->live()->afterStateUpdated(function (Set $set, ?string $state): void {
                        $set('type_scope_confirmed', false);
                        $set('expected_target_requirement_set_id', null);
                        if (filled($state)) {
                            try {
                                $preview = app(RequestAdmissionCorrection::class)->studentTypeScope($this->application(), $state);
                                $set('expected_target_requirement_set_id', $preview['requirement_set_id']);
                            } catch (ValidationException $exception) {
                                Notification::make()->title('Student type correction unavailable')->body($exception->validator->errors()->first())->warning()->send();
                            }
                        }
                    }),
                Placeholder::make('type_correction_scope')->label('Complete dependent correction scope')
                    ->visible(fn (Get $get): bool => in_array('application_path', (array) $get('fields'), true))
                    ->content(function (Get $get): string {
                        if (blank($get('target_application_path'))) {
                            return 'Choose the supported target Student type to preview its required answers and copies.';
                        }
                        try {
                            $preview = app(RequestAdmissionCorrection::class)->studentTypeScope($this->application(), (string) $get('target_application_path'));
                            $set = AdmissionRequirementSet::query()->findOrFail($preview['requirement_set_id']);
                            $labels = collect($preview['scopes'])->map(function (array $scope): string {
                                if ($scope['type'] === ApplicationCorrectionItem::ScopeEvidence) {
                                    return AdmissionRequirement::query()->findOrFail($scope['admission_requirement_id'])->label;
                                }

                                return $scope['key'] === 'accuracy_declared' ? 'Accuracy declaration'
                                    : ($this->correctableFieldOptions()[$scope['key']] ?? str($scope['key'])->headline()->toString());
                            })->all();

                            return 'Requirements version '.$set->version.': '.implode('; ', $labels).'. Earlier submitted copies stay in history. These target requirements and any other selected fields will be named together; unrelated fields remain locked.';
                        } catch (ValidationException $exception) {
                            return $exception->validator->errors()->first();
                        }
                    }),
                Checkbox::make('type_scope_confirmed')->label('I confirm the target Student type and complete dependent scope above.')
                    ->visible(fn (Get $get): bool => in_array('application_path', (array) $get('fields'), true))->accepted()->required(),
                Select::make('evidence_requirements')
                    ->label('Evidence review copies to replace')
                    ->helperText('Select any preliminary evidence requirements where a replacement copy must be uploaded.')
                    ->options($this->requirementOptions())
                    ->default(fn (): array => collect($this->copiesMarkedForCorrection())->pluck('requirement_id')->all())
                    ->visible(fn (Get $get): bool => ! in_array('application_path', (array) $get('fields'), true))
                    ->multiple()
                    ->searchable(),
                Select::make('correction_reason')
                    ->label('Common correction reason')->options([
                        'Unreadable copy' => 'Unreadable copy', 'Incomplete information' => 'Incomplete information',
                        'Details do not match the submitted copy' => 'Details do not match the submitted copy', 'Other' => 'Other',
                    ])->live()->afterStateUpdated(function (Set $set, ?string $state): void {
                        if (filled($state) && $state !== 'Other') {
                            $set('applicant_instruction', $state.'. Correct only the selected items, then resubmit.');
                        }
                    }),
                Textarea::make('applicant_instruction')
                    ->hint('Describe the selected correction')
                    ->default(fn (): ?string => collect($this->copiesMarkedForCorrection())
                        ->map(fn (array $copy): string => $copy['label'].': '.$copy['reason'])
                        ->implode("\n") ?: null)
                    ->label('Applicant instruction')
                    ->helperText('Specific instructions for the Applicant explaining what needs correction.')
                    ->required()
                    ->maxLength(1500),
                DateTimePicker::make('due_at')
                    ->label('Correction due')
                    ->helperText('The applicant is emailed the documents to fix and this due date.')
                    ->validationMessages([
                        'after_or_equal' => fn (): string => 'Choose a time after now ('.$this->formatDeadline(now()).').',
                        'before_or_equal' => fn (): string => 'Choose a time on or before the correction deadline ('.$this->formatDeadline($this->application()->admissionCycle?->correction_closes_at).').',
                    ])
                    ->timezone(config('app.display_timezone'))
                    ->displayFormat('M j, Y · g:i A')
                    ->seconds(false)
                    ->prefixIcon('heroicon-o-calendar-days')
                    ->native(true)
                    ->minDate(fn (): ?string => $this->pickerDayStart(now()))
                    ->maxDate(fn (): ?string => $this->pickerDayEnd($this->application()->admissionCycle?->correction_closes_at))
                    ->rule(fn (): \Closure => $this->exactWindowRule(fn (): CarbonInterface => now(), fn (): ?CarbonInterface => $this->application()->admissionCycle?->correction_closes_at, 'the correction deadline'))
                    ->required(),
            ])
            ->visible(fn (): bool => $this->canReview()
                && $this->application()->application_state === AdmissionApplication::StateSubmitted
                && $this->correctionIssuanceIsOpen())
            ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                $scopes = [];
                foreach ((array) ($data['fields'] ?? []) as $field) {
                    if (filled($field)) {
                        $scopes[] = [
                            'type' => ApplicationCorrectionItem::ScopeField,
                            'key' => (string) $field,
                            'admission_requirement_id' => null,
                        ];
                    }
                }
                foreach ((array) ($data['evidence_requirements'] ?? []) as $requirementId) {
                    if (filled($requirementId)) {
                        $scopes[] = [
                            'type' => ApplicationCorrectionItem::ScopeEvidence,
                            'key' => 'requirement:'.(int) $requirementId,
                            'admission_requirement_id' => (int) $requirementId,
                        ];
                    }
                }

                if (empty($scopes)) {
                    throw ValidationException::withMessages([
                        'fields' => 'Select at least one field or evidence requirement to reopen for correction.',
                    ]);
                }

                app(RequestAdmissionCorrection::class)->execute(
                    $this->application(),
                    $actor,
                    $scopes,
                    (string) $data['applicant_instruction'],
                    (string) ($data['responsible_party'] ?? 'Applicant'),
                    CarbonImmutable::parse((string) $data['due_at']),
                    filled($data['expected_submission_version_id'] ?? null) ? (int) $data['expected_submission_version_id'] : null,
                    in_array('application_path', (array) ($data['fields'] ?? []), true) ? ($data['target_application_path'] ?? null) : null,
                    filled($data['expected_target_requirement_set_id'] ?? null) ? (int) $data['expected_target_requirement_set_id'] : null,
                );
            }, 'Scoped correction requested', $action));
    }

    /**
     * One modal for the whole screening: a result for every submitted copy, then the admission decision (#99 Scope 10).
     * Copy reviews and the decision keep their own records and rules; only the screen is shared.
     */
    public function reviewApplicationAction(): Action
    {
        return Action::make('reviewApplication')
            ->label('Review application')
            ->modalHeading('Review application')
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel(fn (): string => $this->application()->application_state === AdmissionApplication::StateAdmitted ? 'Replace decision' : 'Save review')
            ->icon('heroicon-o-document-magnifying-glass')
            ->modalDescription(function (): string {
                if ($this->application()->application_state === AdmissionApplication::StateAdmitted) {
                    return 'Replace the current Admitted decision for '.$this->application()->application_reference.'. An approval reference is required.';
                }

                return 'Review each submitted document, then record the admission decision. Leave the decision blank to save document results only.';
            })
            ->fillForm(fn (): array => [
                'copies' => collect($this->currentEvidence())->mapWithKeys(function (DocumentEvidence $evidence): array {
                    $review = $this->application()->currentPreliminaryReview($evidence);

                    return [$evidence->id => [
                        'result' => $review?->result,
                        'reason' => $review?->reason,
                        'expected_review_id' => $evidence->preliminaryReviews()->whereDoesntHave('successor')->value('id'),
                    ]];
                })->all(),
                'expected_submission_version_id' => $this->application()->current_submission_version_id,
                'expected_current_decision_id' => $this->application()->decisions()->whereDoesntHave('successor')->value('id'),
                'papers_confirmed' => 0,
            ])
            ->schema(fn (): array => [
                Hidden::make('expected_submission_version_id'),
                Hidden::make('expected_current_decision_id'),
                Section::make('Submitted copies')
                    ->description('Open each copy, then record its result. Action needed requires a remark telling the Applicant what to replace.')
                    ->compact()
                    ->visible($this->currentEvidence() !== [])
                    ->disabled(fn (): bool => $this->application()->application_state !== AdmissionApplication::StateSubmitted)
                    ->schema(collect($this->currentEvidence())->map(fn (DocumentEvidence $evidence): Grid => Grid::make(['default' => 1, 'md' => 12])
                        ->statePath('copies.'.$evidence->id)
                        ->extraAttributes(['class' => 'tala-copy-review-row'])
                        ->schema([
                            Hidden::make('expected_review_id'),
                            Text::make($evidence->admissionRequirement->label)
                                ->weight('medium')
                                ->columnSpan(['default' => 1, 'md' => 4]),
                            Select::make('result')
                                ->label('Result for '.$evidence->admissionRequirement->label)
                                ->hiddenLabel()
                                ->columnSpan(['default' => 1, 'md' => 4])
                                ->options([
                                    PreliminaryEvidenceReview::ResultAccepted => 'Accepted',
                                    PreliminaryEvidenceReview::ResultActionNeeded => 'Action needed',
                                    PreliminaryEvidenceReview::ResultUnderReview => 'Under review',
                                ])
                                ->placeholder('Not reviewed yet')
                                ->live(),
                            Actions::make([
                                Action::make('viewCopy'.$evidence->id)
                                    ->label('View copy')->icon('heroicon-o-eye')->color('gray')->link()
                                    ->url(route('admissions.evidence.view', ['evidence' => $evidence->id]))
                                    ->openUrlInNewTab(),
                                Action::make('downloadCopy'.$evidence->id)
                                    ->label('Download')->icon('heroicon-o-arrow-down-tray')->color('gray')->link()
                                    ->url(route('admissions.evidence.download', ['evidence' => $evidence->id]))
                                    ->openUrlInNewTab(),
                            ])->verticalAlignment('center')->columnSpan(['default' => 1, 'md' => 4]),
                            Textarea::make('reason')
                                ->label('Remarks')
                                ->placeholder('For example: The birth date is unreadable. Upload a clear copy showing the full page.')
                                ->required(fn (Get $get): bool => $get('result') === PreliminaryEvidenceReview::ResultActionNeeded)
                                ->visible(fn (Get $get): bool => in_array($get('result'), [PreliminaryEvidenceReview::ResultActionNeeded, PreliminaryEvidenceReview::ResultUnderReview], true))
                                ->rows(2)
                                ->maxLength(1000)
                                ->columnSpanFull(),
                        ]))->all()),
                Section::make('Admission decision')
                    ->compact()
                    ->description(fn (Get $get): ?string => $this->copiesNeedAction($get('copies'))
                        ? 'A copy needs action, so no decision is recorded now. Save, then use Request corrections to tell the Applicant what to replace.'
                        : null)
                    ->schema([
                        Radio::make('decision')
                            ->live()
                            ->hiddenLabel()
                            ->options([
                                AdmissionDecision::DecisionAdmitted => 'Admitted',
                                AdmissionDecision::DecisionNotAdmitted => 'Not admitted',
                            ])
                            ->disableOptionWhen(fn (string $value, Get $get): bool => $value === AdmissionDecision::DecisionAdmitted
                                && (! $this->copiesAccepted($get('copies')) || $this->identityWarningOptions() !== []))
                            ->helperText(fn (Get $get): ?string => $this->copiesAccepted($get('copies')) && $this->identityWarningOptions() === []
                                ? 'Leave empty to save the copy results only.'
                                : 'Admitted needs every copy Accepted and no open identity warning. Not admitted remains available with a review basis and an Applicant explanation.')
                            ->required(fn (): bool => $this->application()->application_state === AdmissionApplication::StateAdmitted)
                            ->visible(fn (Get $get): bool => ! $this->copiesNeedAction($get('copies'))),
                        Radio::make('papers_confirmed')
                            ->label('Physical requirement checks')
                            ->options([0 => 'Admit — papers pending', 1 => 'Admit — papers confirmed'])
                            ->live()
                            ->visible(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionAdmitted),
                        Checkbox::make('external_checks_confirmed')
                            ->label('I confirm the school completed all required physical requirement checks.')
                            ->visible(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionAdmitted && (bool) $get('papers_confirmed'))
                            ->accepted(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionAdmitted && (bool) $get('papers_confirmed')),
                        Select::make('decision_reason')
                            ->label('Common review reason')->options([
                                'Required evidence is acceptable' => 'Required evidence is acceptable',
                                'Required evidence remains incomplete' => 'Required evidence remains incomplete',
                                'Unsupported admission eligibility' => 'Unsupported admission eligibility',
                                'Submitted documents do not match the current curriculum' => 'Submitted documents do not match the current curriculum',
                                'Other' => 'Other',
                            ])->live()->afterStateUpdated(function (Set $set, ?string $state): void {
                                if (filled($state) && $state !== 'Other') {
                                    $set('reason', $state);
                                }
                            })
                            ->visible(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionNotAdmitted),
                        Textarea::make('reason')->label('Private review reason')
                            ->helperText('Record the basis for the decision. This note is for authorized staff.')
                            ->placeholder('Summarize the reviewed evidence and the reason for this outcome.')
                            ->rows(3)->required()->maxLength(1500)
                            ->visible(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionNotAdmitted),
                        Textarea::make('applicant_explanation')->label('Applicant explanation')
                            ->helperText('Shown to the Applicant. Explain the outcome and a safe next step without private identity or staff details.')
                            ->placeholder('Explain what happens next or how to contact Admissions for help.')
                            ->rows(3)->required()->maxLength(1500)
                            ->visible(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionNotAdmitted),
                        TextInput::make('authority_reference')
                            ->label('Separate approval reference')
                            ->helperText('Required for a replacement decision. Enter the school approval or recorded authorization reference; an ordinary first decision uses your recorded Registrar authority.')
                            ->required(fn (Get $get): bool => filled($get('expected_current_decision_id')) && filled($get('decision')))
                            ->visible(fn (Get $get): bool => filled($get('expected_current_decision_id')) && filled($get('decision')))
                            ->maxLength(255),
                    ]),
            ])
            // Only while the application is under review; after admission the case ends through Record withdrawal (#99 Scope 10).
            ->visible(fn (): bool => $this->canReview()
                && $this->application()->application_state === AdmissionApplication::StateSubmitted)
            ->action(function (array $data, Action $action): mixed {
                $decision = filled($data['decision'] ?? null) && ! $this->copiesNeedAction($data['copies'] ?? []) ? (string) $data['decision'] : null;

                return $this->runAction(function (User $actor) use ($data, $decision): void {
                    DB::transaction(function () use ($actor, $data, $decision): void {
                        $saved = $this->saveCopyReviews($actor, (array) ($data['copies'] ?? []), (int) $data['expected_submission_version_id']);

                        if ($decision === null) {
                            if ($saved === 0) {
                                throw ValidationException::withMessages(['copies' => 'Nothing changed. Record a copy result or the admission decision.']);
                            }

                            return;
                        }

                        app(RecordAdmissionOutcome::class)->execute(
                            $this->application()->fresh(),
                            $actor,
                            $decision,
                            (string) ($data['reason'] ?? self::AdmittedDecisionReason),
                            filled($data['authority_reference'] ?? null) ? (string) $data['authority_reference'] : null,
                            (string) ($data['applicant_explanation'] ?? self::AdmittedApplicantExplanation),
                            filled($data['expected_current_decision_id'] ?? null) ? (int) $data['expected_current_decision_id'] : null,
                            filled($data['expected_submission_version_id'] ?? null) ? (int) $data['expected_submission_version_id'] : null,
                            papersConfirmed: (bool) ($data['papers_confirmed'] ?? false) && (bool) ($data['external_checks_confirmed'] ?? false),
                        );
                    }, attempts: 3);
                }, $decision !== null ? 'Admission decision recorded' : 'Copy reviews saved', $action);
            });
    }

    /**
     * Screening shortcut (#99 Scope 10): one compact row per submitted copy. Ticked means approved; For correction
     * marks the copy Action needed with a remark. With every copy ticked the Registrar admits; with a copy for
     * correction the modal saves the copy results instead, ready for Request corrections.
     */
    public function admitApplicationAction(): Action
    {
        return Action::make('admitApplication')
            ->modalCancelAction(false)
            ->label('Admit')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->modalHeading('Admit application')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalDescription('Check each valid document, or mark it For correction. All documents must be checked to admit.')
            ->modalSubmitActionLabel(fn (): string => $this->admitFormHasCorrections() ? 'Send correction' : 'Admit')
            ->fillForm(fn (): array => [
                'copies' => collect($this->currentEvidence())->mapWithKeys(function (DocumentEvidence $evidence): array {
                    $review = $this->application()->currentPreliminaryReview($evidence);

                    return [$evidence->id => [
                        'passed' => $review?->result === PreliminaryEvidenceReview::ResultAccepted,
                        'for_correction' => $review?->result === PreliminaryEvidenceReview::ResultActionNeeded,
                        'reason' => $review?->result === PreliminaryEvidenceReview::ResultActionNeeded ? $review->reason : null,
                        'expected_review_id' => $evidence->preliminaryReviews()->whereDoesntHave('successor')->value('id'),
                    ]];
                })->all(),
                'expected_submission_version_id' => $this->application()->current_submission_version_id,
                'correction_due_at' => $this->defaultCorrectionDueAt(),
            ])
            ->schema(fn (): array => [
                Hidden::make('expected_submission_version_id'),
                ...collect($this->currentEvidence())->map(fn (DocumentEvidence $evidence): Group => Group::make([
                    Hidden::make('expected_review_id'),
                    Hidden::make('for_correction'),
                    Flex::make([
                        Checkbox::make('passed')
                            ->label($evidence->admissionRequirement->label)
                            ->live()
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                if ($state) {
                                    $set('for_correction', false);
                                    $set('reason', null);
                                }
                            })
                            ->grow(),
                        Text::make('For correction')
                            ->badge()
                            ->color('warning')
                            ->visible(fn (Get $get): bool => (bool) $get('for_correction'))
                            ->grow(false),
                        Actions::make([
                            Action::make('openCopy'.$evidence->id)
                                ->label('Open')
                                ->button()
                                ->size(Size::Medium)
                                ->color('gray')
                                ->icon('heroicon-o-arrow-top-right-on-square')
                                ->extraAttributes(['class' => 'justify-center whitespace-nowrap'])
                                ->url(route('admissions.evidence.view', ['evidence' => $evidence->id]))
                                ->openUrlInNewTab(),
                            Action::make('markForCorrection'.$evidence->id)
                                ->label('For correction')
                                ->color('warning')
                                ->size('sm')
                                ->outlined()
                                ->action(function (Set $set): void {
                                    $set('for_correction', true);
                                    $set('passed', false);
                                }),
                        ])->grow(false),
                    ])->verticallyAlignCenter(),
                    TextInput::make('reason')
                        ->hiddenLabel()
                        ->placeholder('What should the Applicant replace or fix? For example: the copy is unreadable, upload a clear scan.')
                        ->required(fn (Get $get): bool => (bool) $get('for_correction'))
                        ->visible(fn (Get $get): bool => (bool) $get('for_correction'))
                        ->maxLength(1000),
                ])
                    ->statePath('copies.'.$evidence->id)
                    ->extraAttributes(['class' => 'tala-admit-copy-row']))->all(),
                DateTimePicker::make('correction_due_at')
                    ->label('Correction due')
                    ->helperText('The applicant is emailed the documents to fix and this due date.')
                    ->validationMessages([
                        'after_or_equal' => fn (): string => 'Choose a time after now ('.$this->formatDeadline(now()).').',
                        'before_or_equal' => fn (): string => 'Choose a time on or before the correction deadline ('.$this->formatDeadline($this->application()->admissionCycle?->correction_closes_at).').',
                    ])
                    ->timezone(config('app.display_timezone'))
                    ->displayFormat('M j, Y · g:i A')
                    ->seconds(false)
                    ->prefixIcon('heroicon-o-calendar-days')
                    ->native(true)
                    ->minDate(fn (): ?string => $this->pickerDayStart(now()))
                    ->maxDate(fn (): ?string => $this->pickerDayEnd($this->application()->admissionCycle?->correction_closes_at))
                    ->rule(fn (): \Closure => $this->exactWindowRule(fn (): CarbonInterface => now(), fn (): ?CarbonInterface => $this->application()->admissionCycle?->correction_closes_at, 'the correction deadline'))
                    ->visible(fn (Get $get): bool => collect((array) $get('copies'))->contains(fn (mixed $row): bool => is_array($row) && (bool) ($row['for_correction'] ?? false)))
                    ->required(fn (Get $get): bool => collect((array) $get('copies'))->contains(fn (mixed $row): bool => is_array($row) && (bool) ($row['for_correction'] ?? false))),
            ])
            ->visible(fn (): bool => $this->canReview()
                && $this->application()->application_state === AdmissionApplication::StateSubmitted
                && $this->identityWarningOptions() === [])
            ->action(function (array $data, Action $action): mixed {
                $copies = (array) ($data['copies'] ?? []);
                $corrections = collect($copies)->filter(fn (mixed $row): bool => is_array($row) && (bool) ($row['for_correction'] ?? false));

                return $this->runAction(function (User $actor) use ($data, $copies, $corrections): void {
                    if ($corrections->isEmpty()) {
                        $unticked = collect($this->currentEvidence())->reject(fn (DocumentEvidence $evidence): bool => (bool) ($copies[$evidence->id]['passed'] ?? false));
                        if ($unticked->isNotEmpty()) {
                            throw ValidationException::withMessages(['copies' => 'Tick every copy to admit, or mark one For correction. Not ticked: '.$unticked->map(fn (DocumentEvidence $evidence): string => $evidence->admissionRequirement->label)->implode(', ').'.']);
                        }
                    }

                    DB::transaction(function () use ($actor, $data, $copies, $corrections): void {
                        foreach ($this->currentEvidence() as $evidence) {
                            $row = (array) ($copies[$evidence->id] ?? []);
                            $result = match (true) {
                                (bool) ($row['for_correction'] ?? false) => PreliminaryEvidenceReview::ResultActionNeeded,
                                (bool) ($row['passed'] ?? false) => PreliminaryEvidenceReview::ResultAccepted,
                                default => null,
                            };
                            $reason = $result === PreliminaryEvidenceReview::ResultActionNeeded ? trim((string) ($row['reason'] ?? '')) : null;
                            $current = $this->application()->currentPreliminaryReview($evidence);

                            if ($result === null || ($current?->result === $result && trim((string) $current->reason) === (string) $reason)) {
                                continue;
                            }

                            app(ReviewPreliminaryEvidence::class)->execute(
                                $evidence,
                                $actor,
                                $result,
                                $reason,
                                filled($row['expected_review_id'] ?? null) ? (int) $row['expected_review_id'] : null,
                                (int) $data['expected_submission_version_id'],
                                notifyApplicant: false,
                            );
                        }

                        if ($corrections->isNotEmpty()) {
                            // One step: the copies marked For correction become the Applicant's correction request.
                            $marked = collect($this->currentEvidence())->filter(fn (DocumentEvidence $evidence): bool => (bool) ($copies[$evidence->id]['for_correction'] ?? false));
                            app(RequestAdmissionCorrection::class)->execute(
                                $this->application()->fresh(),
                                $actor,
                                $marked->map(fn (DocumentEvidence $evidence): array => [
                                    'type' => ApplicationCorrectionItem::ScopeEvidence,
                                    'key' => 'requirement:'.(int) $evidence->admission_requirement_id,
                                    'admission_requirement_id' => (int) $evidence->admission_requirement_id,
                                ])->values()->all(),
                                $marked->map(fn (DocumentEvidence $evidence): string => $evidence->admissionRequirement->label.': '.trim((string) ($copies[$evidence->id]['reason'] ?? '')))->implode("\n"),
                                'Applicant',
                                CarbonImmutable::parse((string) $data['correction_due_at']),
                                (int) $data['expected_submission_version_id'],
                            );

                            return;
                        }

                        app(RecordAdmissionOutcome::class)->execute(
                            $this->application()->fresh(),
                            $actor,
                            AdmissionDecision::DecisionAdmitted,
                            self::AdmittedDecisionReason,
                            null,
                            self::AdmittedApplicantExplanation,
                            $this->application()->decisions()->whereDoesntHave('successor')->value('id'),
                            (int) $data['expected_submission_version_id'],
                            papersConfirmed: false,
                        );
                    }, attempts: 3);
                }, $corrections->isNotEmpty() ? 'Correction sent to the Applicant' : 'Application admitted', $action);
            });
    }

    /**
     * Current copies whose latest review is Action needed, so Request corrections opens already filled in.
     *
     * @return list<array{requirement_id: int, label: string, reason: string}>
     */
    private function copiesMarkedForCorrection(): array
    {
        return collect($this->currentEvidence())
            ->map(fn (DocumentEvidence $evidence): ?array => ($review = $this->application()->currentPreliminaryReview($evidence))?->result === PreliminaryEvidenceReview::ResultActionNeeded
                ? ['requirement_id' => (int) $evidence->admission_requirement_id, 'label' => $evidence->admissionRequirement->label, 'reason' => (string) $review->reason]
                : null)
            ->filter()
            ->values()
            ->all();
    }

    /** Default correction due date for Send correction. */
    private function defaultCorrectionDueAt(): ?string
    {
        // 5:00 PM school time, three days out, but never past the cycle's correction deadline.
        $due = now(config('app.display_timezone'))->addDays(3)->setTime(17, 0)->timezone(config('app.timezone'));
        $boundary = $this->application()->admissionCycle?->correction_closes_at?->copy()->startOfMinute();
        if ($boundary !== null && $due->greaterThan($boundary)) {
            $due = $boundary;
        }

        return $due->isFuture() ? $due->toDateTimeString() : null;
    }

    /** A copy in the open Admit modal is marked For correction, so the modal sends a correction instead of admitting. */
    private function admitFormHasCorrections(): bool
    {
        return collect((array) data_get($this->mountedActions, '0.data.copies', []))
            ->contains(fn (mixed $row): bool => is_array($row) && (bool) ($row['for_correction'] ?? false));
    }

    /**
     * Record a successor review for each copy whose result or remark changed; unchanged copies keep their review.
     *
     * @param  array<int|string, array{result?: string|null, reason?: string|null, expected_review_id?: int|string|null}>  $copies
     */
    private function saveCopyReviews(User $actor, array $copies, int $expectedSubmissionVersionId): int
    {
        $saved = 0;

        foreach ($this->currentEvidence() as $evidence) {
            $row = $copies[$evidence->id] ?? [];
            $result = $row['result'] ?? null;
            if (blank($result)) {
                continue;
            }

            $reason = filled($row['reason'] ?? null) ? trim((string) $row['reason']) : null;
            $current = $this->application()->currentPreliminaryReview($evidence);
            if ($current?->result === $result && trim((string) $current->reason) === (string) $reason) {
                continue;
            }

            app(ReviewPreliminaryEvidence::class)->execute(
                $evidence,
                $actor,
                (string) $result,
                $result === PreliminaryEvidenceReview::ResultAccepted ? null : $reason,
                filled($row['expected_review_id'] ?? null) ? (int) $row['expected_review_id'] : null,
                $expectedSubmissionVersionId,
            );
            $saved++;
        }

        return $saved;
    }

    /** @param array<int|string, array{result?: string|null}>|null $copies */
    private function copiesAccepted(?array $copies): bool
    {
        return collect($this->currentEvidence())
            ->every(fn (DocumentEvidence $evidence): bool => ($copies[$evidence->id]['result'] ?? null) === PreliminaryEvidenceReview::ResultAccepted);
    }

    /** @param array<int|string, array{result?: string|null}>|null $copies */
    private function copiesNeedAction(?array $copies): bool
    {
        return collect($copies ?? [])->contains(fn (mixed $row): bool => is_array($row) && ($row['result'] ?? null) === PreliminaryEvidenceReview::ResultActionNeeded);
    }

    /**
     * The current copy of each requirement that needs preliminary evidence in the submitted requirement set.
     *
     * @return list<DocumentEvidence>
     */
    private function currentEvidence(): array
    {
        $requirements = $this->application()->currentSubmissionVersion?->requirementSet?->requirements
            ?->where('requires_preliminary_evidence', true)
            ->sortBy('display_order') ?? collect();

        return $requirements
            ->map(fn (AdmissionRequirement $requirement): ?DocumentEvidence => $this->application()->evidenceVersions
                ->where('admission_requirement_id', $requirement->id)
                ->sortByDesc('id')
                ->first())
            ->filter()
            ->values()
            ->all();
    }

    public function sendProcessRemarkAction(): Action
    {
        return Action::make('sendProcessRemark')
            ->modalCancelAction(false)
            ->label('Notify Applicant')
            ->requiresConfirmation()
            ->modalIcon('heroicon-o-paper-airplane')
            ->modalHeading(fn (array $arguments): string => 'Notify the Applicant: '.(SendAdmissionProcessRemark::stepLabels()[$arguments['step'] ?? ''] ?? 'process update'))
            ->modalDescription(fn (array $arguments): string => filled(trim((string) ($this->processRemarks[$arguments['step'] ?? ''] ?? '')))
                ? 'The Applicant receives this remark by email, and it stays in Record history: “'.trim((string) $this->processRemarks[$arguments['step']]).'”'
                : 'Write a remark first so the Applicant knows what to do.')
            ->modalSubmitActionLabel('Send remark')
            ->visible(fn (): bool => (bool) auth()->user()?->can('review', $this->application()))
            ->action(fn (array $arguments, Action $action): mixed => $this->runAction(function (User $actor) use ($arguments): void {
                $step = (string) ($arguments['step'] ?? '');
                app(SendAdmissionProcessRemark::class)->execute($this->application(), $actor, $step, $this->processRemarks[$step] ?? null);
                $this->processRemarks[$step] = null;
            }, 'Applicant notified', $action));
    }

    /**
     * The admission process as an ordered checklist: each step is done, current, stopped or still ahead.
     *
     * @return list<array{key: string, label: string, state: string, summary: string, blocker: string|null, action: string|null, action_label: string|null, remarks: list<array{remark: string, by: string, at: string}>}>
     */
    public function processEvaluation(): array
    {
        $application = $this->application();
        $timezone = (string) config('app.display_timezone');
        $format = fn ($moment): ?string => $moment?->timezone($timezone)->format('M j, Y · g:i A');
        $projection = app(ReadyApplicantProjectionQuery::class)->forApplication($application);
        $decision = $application->decisions()->whereDoesntHave('successor')->latest('decided_at')->first();
        $clearance = $application->enrollmentClearances()->whereDoesntHave('successor')->latest('recorded_at')->first();
        $copies = ($application->currentSubmissionVersion?->requirementSet?->requirements ?? collect())
            ->where('requires_preliminary_evidence', true)
            ->sortBy('display_order')
            ->map(function (AdmissionRequirement $requirement) use ($application): array {
                $evidence = $application->evidenceVersions->where('admission_requirement_id', $requirement->id)->sortByDesc('id')->first();
                $result = $evidence === null ? null : $application->currentPreliminaryReview($evidence)?->result;

                return [
                    'label' => $requirement->label,
                    'accepted' => $result === PreliminaryEvidenceReview::ResultAccepted,
                    'status' => $evidence === null ? 'copy missing' : ($result === null ? 'awaiting review' : str($result)->headline()->lower()->toString()),
                ];
            })
            ->values();
        $copiesAccepted = $copies->every(fn (array $copy): bool => $copy['accepted']);
        $enrollment = Enrollment::query()->where('admission_application_id', $application->id)->whereNull('cancelled_at')->latest('id')->first();
        $verifiedPayment = $enrollment?->termAccount?->payments()->where('state', Payment::StatePosted)->whereNotNull('verified_at')->latest('verified_at')->first();
        $activeCorrection = $application->correctionRequests->where('state', ApplicationCorrectionRequest::StateActive)->sortByDesc('sequence')->first();
        $correctionNote = $activeCorrection === null ? null
            : 'Correction sent to the Applicant: '.$activeCorrection->applicant_instruction.' · due '.$format($activeCorrection->due_at).'.';
        $remarks = $application->events
            ->where('event_type', AdmissionApplicationEvent::TypeProcessRemarkSent)
            ->sortByDesc('occurred_at')
            ->groupBy(fn (AdmissionApplicationEvent $event): string => match ($step = (string) ($event->payload['step'] ?? '')) {
                // Copy review and screening are one step since #99 Scope 10; earlier copy-review remarks show under it.
                SendAdmissionProcessRemark::StepEvidence, SendAdmissionProcessRemark::StepReview => SendAdmissionProcessRemark::StepScreening,
                // The Ready for enrollment step was retired (#103); its earlier remarks show under clearance.
                SendAdmissionProcessRemark::StepReadiness => SendAdmissionProcessRemark::StepClearance,
                default => $step,
            });

        // Opening the case already means review is underway, so there is no separate Start review step.
        $steps = [
            [
                'key' => SendAdmissionProcessRemark::StepScreening,
                'done' => $application->application_state === AdmissionApplication::StateAdmitted,
                'stopped' => $application->application_state === AdmissionApplication::StateNotAdmitted,
                'summary' => collect([
                    $copies->isEmpty()
                        ? 'No preliminary copies are required.'
                        : ($copiesAccepted ? 'All '.$copies->count().' submitted copies accepted' : $copies->where('accepted', true)->count().' of '.$copies->count().' submitted copies accepted'),
                    $decision
                        ? str($decision->decision)->headline().' · '.$format($decision->decided_at)
                        : 'Decision pending',
                ])->implode(' · ').'.',
                'blocker' => $decision ? null : ($correctionNote ?? ($copiesAccepted ? $this->admissionBlockerGuidance() : null)),
                // Each submitted copy and its review status, shown as a list under the step.
                'documents' => $copies->map(fn (array $copy): array => [
                    'label' => $copy['label'],
                    'status' => str($copy['status'])->ucfirst()->toString(),
                    'color' => match (true) {
                        $copy['accepted'] => 'success',
                        in_array($copy['status'], ['copy missing', 'action needed'], true) => 'danger',
                        default => 'warning',
                    },
                ])->all(),
                'action' => $application->reviewHasStarted() ? 'reviewApplication' : 'startReview',
                'button' => $application->reviewHasStarted() ? 'Review application' : 'Start review',
            ],
            [
                'key' => SendAdmissionProcessRemark::StepClearance,
                'done' => $projection['clearance_result'] === RegistrarEnrollmentClearance::ResultCleared,
                'stopped' => false,
                'summary' => ($clearance
                    ? str($clearance->result)->headline().' by '.($clearance->recorder?->getFilamentName() ?? 'Registrar').' · '.$format($clearance->recorded_at)
                    : 'Check the papers passed on-site against the requirement list.')
                    .($clearance?->result === RegistrarEnrollmentClearance::ResultCleared && $this->followedPapers() !== [] ? ' · To be followed: '.implode(', ', $this->followedPapers()) : '')
                    .($projection['ready'] && $enrollment === null && ! $this->canStartEnrollment() ? ' · Enrollment can start once the enrollment period for this term is open.' : ''),
                'blocker' => $clearance?->result === RegistrarEnrollmentClearance::ResultActionNeeded && filled($clearance->safe_instruction)
                    ? 'Applicant was told: '.$clearance->safe_instruction
                    : null,
                'action' => $projection['clearance_result'] === RegistrarEnrollmentClearance::ResultCleared ? null : 'recordEnrollmentClearance',
                'button' => $projection['clearance_result'] === RegistrarEnrollmentClearance::ResultCleared ? null : 'Check papers',
                // Once cleared, enrollment can start even while papers are To be followed (#103): both actions sit on this step.
                'choices' => $projection['clearance_result'] === RegistrarEnrollmentClearance::ResultCleared ? [
                    $enrollment === null
                        ? ['action' => 'startEnrollment', 'label' => 'Start enrollment', 'color' => 'primary', 'icon' => 'heroicon-m-user-plus']
                        : ['action' => 'openEnrollment', 'label' => 'Open enrollment', 'color' => 'gray', 'icon' => 'heroicon-m-arrow-top-right-on-square'],
                    ['action' => 'updateFollowedPapers', 'label' => 'Update papers', 'color' => 'gray', 'icon' => 'heroicon-m-clipboard-document-check'],
                ] : [],
                'persistent_action' => $projection['clearance_result'] === RegistrarEnrollmentClearance::ResultCleared,
            ],
            [
                'key' => SendAdmissionProcessRemark::StepPayment,
                'done' => $enrollment?->officially_enrolled_at !== null || $verifiedPayment !== null,
                'waiting' => $enrollment?->currentProposalVersion?->state !== RegistrationProposalVersion::StateConfirmed,
                'stopped' => false,
                'summary' => match (true) {
                    $verifiedPayment !== null => 'Verified by Accounting · '.$format($verifiedPayment->verified_at),
                    $enrollment?->officially_enrolled_at !== null => 'Enrollment finalized without a separate payment record.',
                    $enrollment?->currentProposalVersion?->state === RegistrationProposalVersion::StateConfirmed => 'Enrollment is for payment. Accounting assesses the fee and records the payment on the enrollment record.',
                    $enrollment !== null => 'Starts once the enrollment is for payment, after the subjects are chosen and confirmed.',
                    default => 'Starts after enrollment begins. Accounting then assesses the fee and verifies the payment; it cannot be overridden.',
                },
                'blocker' => null,
                'action' => null,
                'button' => null,
            ],
            [
                'key' => SendAdmissionProcessRemark::StepEnrolled,
                'done' => $enrollment?->officially_enrolled_at !== null,
                'stopped' => false,
                'summary' => $enrollment?->officially_enrolled_at !== null
                    ? 'Officially enrolled · '.$format($enrollment->officially_enrolled_at).'. The student number is assigned and the student account is active.'
                    : 'The applicant record becomes a student record once enrollment is finalized.',
                'blocker' => null,
                'action' => $enrollment !== null ? 'openEnrollment' : null,
                'button' => $enrollment !== null ? 'Open enrollment' : null,
            ],
        ];

        $currentFound = false;
        $deadline = $this->deadlineStatus();

        return collect($steps)->map(function (array $step) use (&$currentFound, $remarks, $format, $deadline): array {
            if ($step['done']) {
                $state = 'done';
            } elseif ($currentFound) {
                $state = 'upcoming';
            } elseif ($step['waiting'] ?? false) {
                $state = 'upcoming';
                $currentFound = true;
            } else {
                $state = match (true) {
                    $step['stopped'], $deadline['status'] === AdmissionDeadlineStatus::Closed => 'stopped',
                    $deadline['status'] === AdmissionDeadlineStatus::Expired => 'expired',
                    default => 'current',
                };
                $currentFound = true;
            }

            if ($state === 'expired') {
                $step['action'] = 'extendDeadline';
                $step['button'] = 'Re-admit with a new deadline';
                $step['blocker'] = 'Expired: '.$deadline['reason'].' The Applicant was told to contact Admissions support.';
            }

            $action = (in_array($state, ['current', 'expired'], true) || ($step['persistent_action'] ?? false)) && $step['action'] !== null ? $this->getAction($step['action']) : null;
            $actionIsAvailable = $action instanceof Action && $action->isVisible();
            $isClosed = $state === 'stopped' && $deadline['status'] === AdmissionDeadlineStatus::Closed;
            // Screening is a choice, not a remark: Admit or For correction replace the remark box (#99 Scope 10).
            $candidates = match (true) {
                $state === 'current' && $step['key'] === SendAdmissionProcessRemark::StepScreening => [
                    ['action' => 'admitApplication', 'label' => 'Admit', 'color' => 'success', 'icon' => 'heroicon-m-check-circle'],
                    ['action' => 'requestCorrection', 'label' => 'For correction', 'color' => 'warning', 'icon' => 'heroicon-m-pencil-square'],
                ],
                (bool) ($step['persistent_action'] ?? false) => $step['choices'] ?? [],
                default => [],
            };
            $choices = collect($candidates)
                ->filter(fn (array $choice): bool => ($candidate = $this->getAction($choice['action'])) instanceof Action && $candidate->isVisible())
                ->values()->all();

            return [
                'key' => $step['key'],
                'label' => SendAdmissionProcessRemark::stepLabels()[$step['key']],
                'state' => $state,
                'stop_label' => $isClosed ? 'Closed' : 'Rejected',
                'summary' => match (true) {
                    $isClosed => 'Closed. '.$deadline['reason'].' This is final; the Applicant creates a new account for a later term.',
                    $state === 'stopped' => 'Rejected. This is final; the application cannot be corrected or resubmitted.',
                    default => $step['summary'],
                },
                'blocker' => in_array($state, ['current', 'expired'], true) ? $step['blocker'] : null,
                'action' => $actionIsAvailable ? $step['action'] : null,
                'action_label' => $actionIsAvailable ? $step['button'] : null,
                'persistent_action' => (bool) ($step['persistent_action'] ?? false),
                'choices' => $choices,
                'documents' => $step['documents'] ?? [],
                'remarks' => ($remarks->get($step['key']) ?? collect())->map(fn (AdmissionApplicationEvent $event): array => [
                    'remark' => (string) ($event->payload['remark'] ?? ''),
                    'by' => $event->actor?->getFilamentName() ?? 'Registrar',
                    'at' => (string) $format($event->occurred_at),
                ])->values()->all(),
            ];
        })->all();
    }

    /**
     * Papers the school receives outside TALA, grouped by how they reach the Registrar.
     *
     * @return array<string, array{label: string, items: list<array{id: int, label: string, due: string, required: bool, result: string|null, result_id: int|null, status: string, tone: string, requirement: AdmissionRequirement}>}>
     */
    private function clearanceChecklist(): array
    {
        return app(AdmissionPaperChecklist::class)->categories($this->application());
    }

    /**
     * #103 Scope 7: one checkbox per paper. Ticked papers become Passed; unticked ones stay To be followed.
     * A paper already Passed is never un-passed from here; Update papers lists only the papers still to be followed.
     *
     * @return list<CheckboxList>
     */
    private function paperChecklistFields(bool $onlyFollowed = false): array
    {
        return AdmissionPaperFields::make($this->clearanceChecklist(), $onlyFollowed);
    }

    /** @return list<string> labels of papers not yet Passed */
    private function followedPapers(): array
    {
        return app(AdmissionPaperChecklist::class)->followed($this->application());
    }

    /** @param array<string, mixed> $data */
    private function recordClearanceWithChecklist(User $actor, array $data): void
    {
        $items = collect($this->clearanceChecklist())->flatMap(fn (array $category): array => $category['items']);
        if ($items->isEmpty() && ! (bool) ($data['external_checks_confirmed'] ?? false)) {
            throw ValidationException::withMessages(['external_checks_confirmed' => 'Confirm the required external enrollment checks are complete.']);
        }

        DB::transaction(function () use ($actor, $data): void {
            $application = $this->application();
            $authority = filled($data['authority_reference'] ?? null) ? (string) $data['authority_reference'] : AdmissionPaperChecklist::PassedAuthority;
            app(AdmissionPaperChecklist::class)->recordPassed($application, $actor, AdmissionPaperFields::checkedIds($data), $authority);

            app(RecordRegistrarEnrollmentClearance::class)->execute(
                $application,
                $actor,
                RegistrarEnrollmentClearance::ResultCleared,
                true,
                null,
                $data['reason'] ?? null,
                $data['authority_reference'] ?? null,
                filled($data['expected_current_clearance_id'] ?? null) ? (int) $data['expected_current_clearance_id'] : null,
                filled($data['expected_decision_id'] ?? null) ? (int) $data['expected_decision_id'] : null,
                filled($data['expected_submission_version_id'] ?? null) ? (int) $data['expected_submission_version_id'] : null,
            );
        });
    }

    /** @param array<string, mixed> $data */
    private function recordPassedPapers(User $actor, array $data): void
    {
        DB::transaction(fn () => app(AdmissionPaperChecklist::class)->recordPassed($this->application(), $actor, AdmissionPaperFields::checkedIds($data)));
    }

    private function application(): AdmissionApplication
    {
        $record = $this->getRecord();
        abort_unless($record instanceof AdmissionApplication, 404);

        return $record;
    }

    public function canReview(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('review', $this->application()) && $this->application()->reviewHasStarted() && ! $this->isClosed();
    }

    /**
     * @return array{status: string|null, kind: string|null, deadline: CarbonInterface|null, reason: string|null}
     */
    public function deadlineStatus(): array
    {
        return $this->deadlineStatusCache ??= app(AdmissionDeadlineStatus::class)->for($this->application());
    }

    /**
     * The Registrar can start a cleared Applicant's enrollment at the desk; the Applicant need not sign in.
     * Before clearance (requireReady: false) this only checks that a combined "clear and start" makes sense.
     */
    private function canStartEnrollment(bool $requireReady = true): bool
    {
        $actor = auth()->user();

        return $actor instanceof User
            && $actor->hasRole(User::StaffRoleRegistrar)
            && $this->canReview()
            && $this->application()->application_state === AdmissionApplication::StateAdmitted
            && $this->deadlineStatus()['status'] === null
            && $this->currentEnrollment() === null
            && (! $requireReady || app(ReadyApplicantProjectionQuery::class)->forApplication($this->application())['ready']);
    }

    private function currentEnrollment(): ?Enrollment
    {
        return $this->currentEnrollmentCache ??= Enrollment::query()
            ->where('admission_application_id', $this->application()->id)
            ->whereNull('cancelled_at')
            ->latest('id')
            ->first();
    }

    private function startEnrollmentAndOpen(bool $afterClearance = false): void
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        $prefix = $afterClearance ? 'Clearance is recorded, but enrollment did not start. ' : '';

        try {
            $application = $this->application()->fresh();
            $term = Term::query()->find($application->term_id);
            if (! $term instanceof Term) {
                throw ValidationException::withMessages(['term' => 'This application has no admitted term.']);
            }

            $enrollment = app(StartRegistrationCase::class)->forReadyApplicant($application, $term, $actor, 'RegistrarAssisted', null);
            Notification::make()
                ->title('Enrollment started')
                ->body('Review the proposed subjects with the Applicant, then Accounting assesses the fee and records the payment.')
                ->success()
                ->send();
            $this->redirect(EnrollmentResource::getUrl('view', ['record' => $enrollment]));
        } catch (CalendarGateViolation) {
            Notification::make()->title('Enrollment not started')->body($prefix.'The enrollment window for this term is not open.')->warning()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title('Enrollment not started')->body($prefix.$exception->validator->errors()->first())->warning()->send();
        } catch (AuthorizationException $exception) {
            Notification::make()->title('Enrollment not started')->body($prefix.$exception->getMessage())->danger()->send();
        }
    }

    private function isClosed(): bool
    {
        return $this->deadlineStatus()['status'] === AdmissionDeadlineStatus::Closed;
    }

    private function readinessIsCleared(): bool
    {
        return app(ReadyApplicantProjectionQuery::class)->forApplication($this->application())['clearance_result'] === RegistrarEnrollmentClearance::ResultCleared;
    }

    /**
     * Date-picker limits are whole school days (#99 Scope 9/12).
     *
     * Filament resets the time to 0:00 whenever the time being stepped through falls outside min/max, so an
     * exact "now" bound made the hour impossible to raise on today's date. The calendar therefore limits days
     * only, and {@see exactWindowRule()} enforces the exact times on the server. Bounds are school wall time
     * without an offset because the picker's value is school wall time until after validation.
     */
    private function pickerDayStart(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->timezone((string) config('app.display_timezone'))->startOfDay()->format('Y-m-d H:i:s');
    }

    private function pickerDayEnd(?CarbonInterface $moment): ?string
    {
        return $moment?->copy()->timezone((string) config('app.display_timezone'))->endOfDay()->format('Y-m-d H:i:s');
    }

    /**
     * Exact-time check inside the allowed days: after now, and on or before the latest moment (if any).
     *
     * @param  \Closure(): CarbonInterface  $earliest
     * @param  (\Closure(): ?CarbonInterface)|null  $latest
     */
    private function exactWindowRule(\Closure $earliest, ?\Closure $latest, string $latestLabel): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($earliest, $latest, $latestLabel): void {
            if (blank($value)) {
                return;
            }

            $timezone = (string) config('app.display_timezone');
            $chosen = CarbonImmutable::parse((string) $value, $timezone);
            $from = $earliest()->copy()->timezone($timezone);
            $until = $latest !== null ? $latest()?->copy()->timezone($timezone) : null;
            // Whole-day misses are reported by the picker's own min/max rule.
            if ($chosen->lessThan($from->copy()->startOfDay()) || ($until !== null && $chosen->greaterThan($until->copy()->endOfDay()))) {
                return;
            }

            if (! $chosen->greaterThan($from)) {
                $fail('Choose a time after now ('.$this->formatDeadline($earliest()).').');
            } elseif ($until !== null && $chosen->greaterThan($until)) {
                $fail('Choose a time on or before '.$latestLabel.' ('.$this->formatDeadline($latest()).').');
            }
        };
    }

    /** Some time remains between now and the cycle's correction boundary (or no boundary is set). */
    private function correctionWindowIsOpen(): bool
    {
        $closesAt = $this->application()->admissionCycle?->correction_closes_at;

        return $closesAt === null || $closesAt->isFuture();
    }

    public function correctionWindowGuidance(): string
    {
        $closesAt = $this->application()->admissionCycle?->correction_closes_at;

        if ($closesAt === null) {
            return 'Choose a time after now. No correction deadline is set for this cycle.';
        }

        if (! $closesAt->isFuture()) {
            return 'No time is left: corrections closed '.$this->formatDeadline($closesAt).'. Move the correction deadline in the cycle\'s Set admission deadlines first.';
        }

        $window = 'Allowed: after now ('.$this->formatDeadline(now()).') and on or before the correction deadline ('.$this->formatDeadline($closesAt).').';

        return $closesAt->lessThanOrEqualTo(now()->addDay())
            ? $window.' Warning: corrections close in '.now()->diffForHumans($closesAt, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]).'.'
            : $window;
    }

    /** Some time remains between now and the end of the admission period (or no period end is set). */
    private function deadlineWindowIsOpen(): bool
    {
        $periodEndsAt = $this->application()->admissionCycle?->period_ends_at;

        return $periodEndsAt === null || $periodEndsAt->isFuture();
    }

    public function deadlineWindowGuidance(): string
    {
        $periodEndsAt = $this->application()->admissionCycle?->period_ends_at;

        if ($periodEndsAt === null) {
            return 'Choose a time after now. No admission-period end is set for this cycle.';
        }

        if (! $periodEndsAt->isFuture()) {
            return 'No time is left: the admission period ended '.$this->formatDeadline($periodEndsAt).'. Move the admission period end in the cycle\'s Set admission deadlines first.';
        }

        $window = 'Allowed: after now ('.$this->formatDeadline(now()).') and on or before the end of the admission period ('.$this->formatDeadline($periodEndsAt).').';

        return $periodEndsAt->lessThanOrEqualTo(now()->addDay())
            ? $window.' Warning: the admission period ends in '.now()->diffForHumans($periodEndsAt, ['syntax' => CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2]).', so very little time can be given.'
            : $window;
    }

    private function formatDeadline(mixed $moment): ?string
    {
        return $moment?->copy()->timezone((string) config('app.display_timezone'))->format('M j, Y · g:i A');
    }

    private function canResolveIdentity(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('resolveIdentity', $this->application()) && $this->application()->reviewHasStarted();
    }

    private function canManageAdmissionSetup(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('manage-admission-setup');
    }

    private function correctionIssuanceIsOpen(): bool
    {
        $boundary = $this->application()->admissionCycle?->correction_closes_at;

        return $boundary !== null && now(config('app.timezone'))->lessThanOrEqualTo($boundary);
    }

    private function failedNotification(): ?OperationalEvent
    {
        return OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainNotifications)
            ->where('related_record_type', AdmissionApplication::class)
            ->where('related_record_id', $this->application()->id)
            ->where('status', OperationalEvent::StatusFailed)
            ->latest('failed_at')
            ->first();
    }

    /** @param callable(User): void $operation */
    private function runAction(callable $operation, string $successTitle, ?Action $action = null): mixed
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation($actor);
            Notification::make()->title($successTitle)->success()->send();
            $parameters = ['record' => $this->application()];
            if ($this->queue !== null) {
                $parameters['queue'] = $this->queue;
            }
            $this->redirect(AdmissionApplicationResource::getUrl('view', $parameters));
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Registrar action blocked')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();

            $action?->halt();
        }

        return null;
    }

    /** @return array<string, string> */
    private function correctableFieldOptions(): array
    {
        return ApplicationCorrectionItem::fieldOptions();
    }

    private function admissionBlockerGuidance(): ?string
    {
        $application = $this->application();
        $requirements = $application->currentSubmissionVersion?->requirementSet?->requirements;
        $blockers = [];

        if ($requirements === null) {
            $blockers[] = 'Submitted requirement version unavailable: restore the source before recording Admitted.';
        } else {
            foreach ($requirements->where('due_stage', AdmissionRequirement::DuePreliminaryReview) as $requirement) {
                $evidence = $application->evidenceVersions->where('admission_requirement_id', $requirement->id)->sortByDesc('id')->first();
                $review = $evidence?->preliminaryReviews->first(fn (PreliminaryEvidenceReview $review): bool => $review->successor === null);
                if ($review?->result !== PreliminaryEvidenceReview::ResultAccepted) {
                    $result = $evidence === null ? 'copy missing' : ($review === null ? 'review pending' : str($review->result)->headline()->toString());
                    $blockers[] = $requirement->label.': '.$result.'. Review the current copy or request a replacement.';
                }
            }
        }

        foreach ($this->identityWarningOptions() as $warning) {
            $blockers[] = $warning.': use Resolve identity warning with supporting evidence.';
        }

        return $blockers === [] ? null : 'Admitted is blocked. '.implode(' ', $blockers).' Not admitted remains available with a review basis and Applicant explanation.';
    }

    /** @return array<int, string> */
    private function requirementOptions(): array
    {
        return $this->application()->currentSubmissionVersion?->requirementSet?->requirements
            ->where('requires_preliminary_evidence', true)
            ->sortBy('display_order')
            ->pluck('label', 'id')
            ->all() ?? [];
    }

    /** @return array<int, string> */
    private function identityWarningOptions(): array
    {
        return $this->application()->identityMatchReviews
            ->where('outcome', IdentityMatchReview::OutcomePending)
            ->mapWithKeys(fn (IdentityMatchReview $review): array => [
                $review->id => str($review->match_type)->headline()->toString().' — private review '.$review->id,
            ])->all();
    }
}
