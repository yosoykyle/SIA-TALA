<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Admissions\ResolveAdmissionRequirementSet;
use App\Actions\Applicants\ApplicantEntryReadinessService;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Finance\StudentTermAccountPresenter;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\ApplicationCorrectionRequest;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\PreliminaryEvidenceReview;
use App\Models\Program;
use App\Models\PublicNotice;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use App\Queries\Admissions\AdmissionDeadlineStatus;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class Dashboard extends BaseDashboard implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.applicant.pages.dashboard';

    #[Url(as: 'application')]
    public ?int $sourceApplicationId = null;

    public function getTitle(): string
    {
        return 'Applicant Home';
    }

    public function mount(): void
    {
        if ($this->shouldShowHomeNoticeModal()) {
            $this->dispatch('open-modal', id: 'applicant-home-notice');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openEnrollment')
                ->label('Open enrollment')
                ->icon('heroicon-o-academic-cap')
                ->url(fn (): string => \App\Filament\Applicant\Pages\Enrollment::getUrl())
                ->visible(function (): bool {
                    $application = $this->currentApplication();

                    return $this->registrationCase() instanceof Enrollment
                        || ($application instanceof AdmissionApplication && app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready']);
                }),
        ];
    }

    public function resendFailedNotificationAction(): Action
    {
        return Action::make('resendFailedNotification')
            ->label('Resend failed update')
            ->icon('heroicon-o-paper-airplane')
            ->color('warning')
            ->requiresConfirmation()
            ->modalWidth(Width::Large)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('This retries only the latest failed admissions email. Your authoritative Application state will not change.')
            ->visible(fn (): bool => $this->failedNotification() instanceof OperationalEvent)
            ->action(function (): void {
                $actor = Auth::user();
                $event = $this->failedNotification();
                abort_unless($actor instanceof User && $event instanceof OperationalEvent, 404);

                app(AdmissionNotificationLedger::class)->resend($event, $actor);
                Notification::make()
                    ->title('Admissions update queued again')
                    ->body('Your Application state was unchanged. Check your email or return here if delivery fails again.')
                    ->success()
                    ->send();
                $this->redirect(self::getUrl());
            });
    }

    public function withdrawApplicationAction(): Action
    {
        return Action::make('withdrawApplication')
            ->label('Withdraw application')
            ->icon('heroicon-o-archive-box-x-mark')
            ->color('danger')
            ->outlined()
            ->requiresConfirmation()
            ->modalWidth(Width::TwoExtraLarge)
            ->stickyModalHeader()
            ->stickyModalFooter()
            ->modalDescription('Your Application reference and submission history remain saved. Withdrawal is blocked once enrollment registration starts.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason (optional)')
                    ->maxLength(500),
            ])
            ->visible(fn (): bool => $this->canWithdraw())
            ->action(function (array $data): void {
                $application = $this->currentApplication();
                $applicant = Auth::user();
                abort_unless($application instanceof AdmissionApplication && $applicant instanceof User, 404);

                try {
                    app(ChangeAdmissionApplicationLifecycle::class)->withdrawByApplicant(
                        $application,
                        $applicant,
                        filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                    );
                    Notification::make()->title('Application withdrawn')->success()->send();
                    $this->redirect(self::getUrl());
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title('Application cannot be withdrawn')
                        ->body($exception->validator->errors()->first())
                        ->danger()
                        ->send();
                }
            });
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AdmissionApplication::query()
                ->canonical()
                ->with(['admissionCycle', 'program', 'currentSubmissionVersion'])
                ->where('user_id', Auth::id()))
            ->columns([
                TextColumn::make('application_reference')->label('Reference')->placeholder('Draft')->searchable()->copyable()->wrap(),
                TextColumn::make('scope')
                    ->label('Cycle / Program')
                    ->state(function (AdmissionApplication $record): string {
                        $cycle = $record->getRelation('admissionCycle');

                        return $cycle instanceof AdmissionCycle ? $cycle->label : 'Cycle unavailable';
                    })
                    ->description(function (AdmissionApplication $record): string {
                        $program = $record->getRelation('program');

                        return $program instanceof Program ? Program::applicantName($program->name) : 'Program not selected';
                    })
                    ->wrap(),
                TextColumn::make('application_path')
                    ->label('Student type')
                    ->formatStateUsing(fn (string $state): string => AdmissionCycle::studentTypeLabel($state)),
                TextColumn::make('application_state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                    ->color(fn (AdmissionApplication $record): string|array => $this->homeStatusColor($record)),
                TextColumn::make('updated_at')->label('Last activity')->dateTime()->sortable(),
            ])
            ->stackedOnMobile()
            ->extraAttributes(['class' => 'tala-applicant-history-table'])
            ->recordActions([
                Action::make('continue')
                    ->button()
                    ->label(fn (?AdmissionApplication $record): string => $record?->application_state === AdmissionApplication::StateDraft
                        && ! app(ReadyApplicantProjectionQuery::class)->draftCycleIsOpen($record)
                            ? 'Inspect saved draft' : 'Continue')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (AdmissionApplication $record): string => Application::getUrl(['application' => $record->id]))
                    ->visible(fn (AdmissionApplication $record): bool => in_array($record->application_state, [
                        AdmissionApplication::StateDraft,
                        AdmissionApplication::StateActionNeeded,
                    ], true)),
                Action::make('acknowledgment')
                    ->label('Acknowledgment')
                    ->icon('heroicon-o-printer')
                    ->url(fn (AdmissionApplication $record): string => route('admissions.application.acknowledgment', [
                        'application' => $record,
                        'version' => $record->currentSubmissionVersion,
                    ]))
                    ->openUrlInNewTab()
                    ->visible(fn (AdmissionApplication $record): bool => $record->currentSubmissionVersion !== null),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyState(view('filament.partials.collection-empty-state'))
            ->emptyStateHeading(fn (): string => filled($this->getTableSearch()) ? 'No matching applications' : 'No application yet')
            ->emptyStateDescription(fn (): string => filled($this->getTableSearch()) ? 'Try another reference or clear the search.' : 'Start one application while a published Admission Cycle is open.');
    }

    public function currentApplication(): ?AdmissionApplication
    {
        $query = AdmissionApplication::query()
            ->canonical()
            ->with([
                'admissionCycle',
                'program',
                'currentSubmissionVersion.requirementSet.requirements',
                'submissionVersions.requirementSet',
                'evidenceVersions.preliminaryReviews',
                'credentialResults.requirement',
                'correctionRequests.items',
                'decisions',
                'events',
            ])
            ->where('user_id', Auth::id());

        return $this->sourceApplicationId !== null
            ? $query->findOrFail($this->sourceApplicationId)
            : $query->latest('updated_at')->first();
    }

    public function registrationCase(): ?Enrollment
    {
        $application = $this->currentApplication();
        if (! $application instanceof AdmissionApplication) {
            return null;
        }

        return Enrollment::query()
            ->with(['currentProposalVersion.items.section', 'currentProposalVersion.confirmation', 'termAccount'])
            ->where('admission_application_id', $application->id)
            ->where('credential_user_id', $application->user_id)
            ->where('term_id', $application->term_id)
            ->first();
    }

    /** @return array<string, mixed>|null */
    public function registrationReadiness(): ?array
    {
        $case = $this->registrationCase();

        return $case instanceof Enrollment ? app(RegistrationReadinessQuery::class)->for($case) : null;
    }

    private function registrationProposalSummary(): string
    {
        $proposal = $this->registrationCase()?->currentProposalVersion;
        if (! $proposal instanceof RegistrationProposalVersion) {
            return 'The current proposal is unavailable. Refresh before confirming.';
        }

        return 'Confirm proposed subjects: '.$proposal->items
            ->map(fn ($item): string => collect([
                $item->course_code_snapshot,
                $item->section?->code,
                $item->units_snapshot.' units',
            ])->filter()->implode(' · '))
            ->implode('; ').'.';
    }

    /** @return array<string, mixed> */
    public function readinessProjection(AdmissionApplication $application): array
    {
        return app(ReadyApplicantProjectionQuery::class)->forApplication($application);
    }

    public function admissionsAreOpen(): bool
    {
        return AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->exists();
    }

    /**
     * Expired, Closed and Rejected (QA workflow) take precedence over the stored state on Home.
     *
     * @return array{status: string|null, kind: string|null, deadline: CarbonInterface|null, reason: string|null}
     */
    public function deadlineStatus(AdmissionApplication $application): array
    {
        return app(AdmissionDeadlineStatus::class)->for($application);
    }

    public function homeStatusLabel(AdmissionApplication $application): string
    {
        return match (true) {
            $this->deadlineStatus($application)['status'] === AdmissionDeadlineStatus::Closed => 'Application closed',
            $this->deadlineStatus($application)['status'] === AdmissionDeadlineStatus::Expired => 'Expired',
            $application->application_state === AdmissionApplication::StateNotAdmitted => 'Application rejected',
            default => $this->statusLabel($application->application_state),
        };
    }

    /** @return string|array<int|string, string> */
    public function homeStatusColor(AdmissionApplication $application): string|array
    {
        return match (true) {
            $this->deadlineStatus($application)['status'] === AdmissionDeadlineStatus::Closed => 'gray',
            $this->deadlineStatus($application)['status'] === AdmissionDeadlineStatus::Expired => AdmissionApplication::stateColor('Expired'),
            $application->application_state === AdmissionApplication::StateNotAdmitted => 'danger',
            default => $this->statusColor($application->application_state),
        };
    }

    /**
     * The Home notice for a final or expired application: what happened and what the Applicant can do.
     *
     * @return array{tone: string, title: string, body: string}|null
     */
    public function finalNotice(?AdmissionApplication $application): ?array
    {
        if (! $application instanceof AdmissionApplication) {
            return null;
        }

        $deadline = $this->deadlineStatus($application);

        if ($deadline['status'] === AdmissionDeadlineStatus::Closed) {
            return ['tone' => 'neutral', 'title' => 'Application closed', 'body' => $deadline['reason'].' Applications cannot be moved to another term. Create a new account to apply when the next admission period opens.'];
        }

        if ($deadline['status'] === AdmissionDeadlineStatus::Expired) {
            return ['tone' => 'warning', 'title' => 'Application expired', 'body' => $deadline['reason'].' Contact Admissions support. The Registrar can set a new deadline while the admission period is open.'];
        }

        if ($application->application_state === AdmissionApplication::StateNotAdmitted) {
            $explanation = $application->decisions
                ->filter(fn ($decision): bool => $decision->successor === null)
                ->sortByDesc('decided_at')
                ->first()?->applicant_explanation;

            return ['tone' => 'danger', 'title' => 'Application rejected', 'body' => (filled($explanation) ? 'Reason from the Registrar: '.$explanation.' ' : '').'This decision is final. The application cannot be corrected or resubmitted.'];
        }

        return null;
    }

    public function statusLabel(string $state): string
    {
        return str($state)->headline()->toString();
    }

    /** @return string|array<int|string, string> */
    public function statusColor(string $state): string|array
    {
        return AdmissionApplication::stateColor($state);
    }

    /**
     * The five factual admission stages shown on Home, derived from existing records without new stored states.
     *
     * A stage may carry `statusText` to replace the generic status word, such as "Not started" before any application exists.
     *
     * @return list<array{label: string, status: 'completed'|'current'|'pending'|'unavailable', detail: string, statusText?: string}>
     */
    public function journeyStages(?AdmissionApplication $application): array
    {
        $labels = ['Application', 'Requirements', 'Registrar review', 'Admission decision', 'Enrollment clearance'];

        if (! $application instanceof AdmissionApplication) {
            return collect($labels)->map(fn (string $label, int $index): array => $index === 0
                ? ['label' => $label, 'status' => 'current', 'detail' => 'Not started', 'statusText' => 'Not started']
                : ['label' => $label, 'status' => 'pending', 'detail' => 'Pending'])->all();
        }

        $projection = $this->readinessProjection($application);
        $query = app(ReadyApplicantProjectionQuery::class);
        $isSubmitted = $application->currentSubmissionVersion !== null;
        $currentDecision = $application->decisions->firstWhere('id', $projection['decision_id']);
        $isNotAdmitted = $application->application_state === AdmissionApplication::StateNotAdmitted;

        $completed = [
            $isSubmitted,
            $isSubmitted && $query->preliminaryReviewIsComplete($application),
            $currentDecision !== null,
            $currentDecision !== null,
            (bool) $projection['ready'],
        ];
        $details = [
            $isSubmitted ? 'Submitted' : 'Not submitted',
            $completed[1] ? 'Required copies accepted' : ($isSubmitted ? 'Registrar is checking your copies' : 'Upload with your application'),
            $currentDecision !== null ? 'Review complete' : ($isSubmitted ? ($application->reviewHasStarted() ? 'Registrar is reviewing' : 'Waiting for review') : 'After you submit'),
            $currentDecision !== null ? str($currentDecision->decision)->headline().' · '.$currentDecision->decided_at?->timezone(config('app.display_timezone'))->format('M j, Y') : 'Pending',
            $projection['clearance_result'] ? str($projection['clearance_result'])->headline()->toString() : 'Pending',
        ];

        $currentIndex = collect($completed)->search(false);
        $isClosed = $this->deadlineStatus($application)['status'] === AdmissionDeadlineStatus::Closed;
        $stops = $isClosed || in_array($application->application_state, [AdmissionApplication::StateNotAdmitted, AdmissionApplication::StateWithdrawn], true);

        return collect($labels)->map(function (string $label, int $index) use ($completed, $details, $currentIndex, $stops, $isNotAdmitted, $isClosed): array {
            $status = match (true) {
                $completed[$index] => 'completed',
                $stops => 'unavailable',
                $index === $currentIndex => 'current',
                default => 'pending',
            };

            return [
                'label' => $label,
                'status' => $status,
                'detail' => $status === 'unavailable' ? match (true) {
                    $isClosed => 'Closed',
                    $isNotAdmitted => 'Not applicable',
                    default => 'Stopped',
                } : $details[$index],
            ];
        })->all();
    }

    /**
     * The 1-based stage the Applicant is on: the stage in progress, the last completed stage when the journey stopped,
     * or the last stage when all are done.
     *
     * @param  list<array{label: string, status: string, detail: string}>  $stages
     */
    public function journeyStepNumber(array $stages): int
    {
        $stages = collect($stages);
        $current = $stages->search(fn (array $stage): bool => $stage['status'] === 'current');

        if ($current !== false) {
            return $current + 1;
        }

        $lastCompleted = $stages->keys()->filter(fn (int $index): bool => $stages[$index]['status'] === 'completed')->last();

        return $lastCompleted === null ? 1 : $lastCompleted + 1;
    }

    /**
     * One plain-language sentence naming the current situation and the office that acts next.
     */
    public function statusExplanation(AdmissionApplication $application): string
    {
        $query = app(ReadyApplicantProjectionQuery::class);
        $deadline = $this->deadlineStatus($application);

        if ($deadline['status'] === AdmissionDeadlineStatus::Closed) {
            return 'The admission period ended before this application was finished. Create a new account for a later term.';
        }

        if ($deadline['status'] === AdmissionDeadlineStatus::Expired) {
            return $deadline['reason'].' Contact Admissions support about a new deadline.';
        }

        if ($application->application_state === AdmissionApplication::StateAdmitted) {
            $projection = $this->readinessProjection($application);

            return match (true) {
                (bool) $projection['ready'] => 'You are admitted and cleared by the Registrar for enrollment.',
                $projection['clearance_result'] === 'ActionNeeded' => 'You are admitted. The Registrar needs something from you before enrollment clearance.',
                default => 'You are admitted. The Registrar is completing your enrollment clearance after the school\'s checks.',
            };
        }

        return match ($application->application_state) {
            AdmissionApplication::StateDraft => $query->draftCycleIsOpen($application)
                ? 'Not yet submitted. Finish and submit your application before applications close.'
                : 'Not submitted. This admission cycle is closed, so you can only inspect or discard this draft.',
            AdmissionApplication::StateSubmitted => $application->allowsSubmittedEdit()
                ? 'Awaiting Registrar review. You can still update your application until the Registrar starts reviewing it.'
                : 'The Registrar is reviewing your application.',
            AdmissionApplication::StateActionNeeded => 'The Registrar asked you to correct part of your application.',
            AdmissionApplication::StateNotAdmitted => 'The Registrar rejected this application. The decision is final.',
            AdmissionApplication::StateWithdrawn => 'This application was withdrawn. Contact the Registrar if it needs to be reopened.',
            default => 'Contact the Registrar about this application.',
        };
    }

    /**
     * Dates the Applicant can rely on, taken only from the Admission Cycle and an active correction.
     *
     * @return list<array{label: string, value: string}>
     */
    public function importantDates(AdmissionApplication $application): array
    {
        $format = fn ($instant): string => $instant->timezone(config('app.display_timezone'))->format('M j, Y, g:i A');
        $cycle = $application->admissionCycle;
        $correction = $application->correctionRequests
            ->where('state', ApplicationCorrectionRequest::StateActive)
            ->sortByDesc('sequence')
            ->first();

        return array_values(array_filter([
            $cycle?->opens_at ? ['label' => 'Applications open', 'value' => $format($cycle->opens_at)] : null,
            $cycle?->closes_at ? ['label' => 'Applications close', 'value' => $format($cycle->closes_at)] : null,
            $correction?->due_at ? ['label' => $correction->isOverdue() ? 'Correction overdue since' : 'Correction due', 'value' => $format($correction->due_at)] : null,
            ($papersDue = app(AdmissionDeadlineStatus::class)->requirementsDeadline($application)) ? ['label' => 'Physical papers due', 'value' => $format($papersDue)] : null,
            ($paymentDue = app(AdmissionDeadlineStatus::class)->paymentDeadline($application)) ? ['label' => 'Enrollment payment due', 'value' => $format($paymentDue)] : null,
            $cycle?->period_ends_at ? ['label' => 'Admission period ends', 'value' => $format($cycle->period_ends_at)] : null,
            ['label' => 'Decision', 'value' => 'The Registrar reviews submitted applications. You will get an email when there is an update.'],
        ]));
    }

    /**
     * True only for a real date-based fact (not the generic decision note), so an empty cycle/correction
     * does not force the login notice open on its own.
     */
    public function hasImportantDates(?AdmissionApplication $application): bool
    {
        if (! $application instanceof AdmissionApplication) {
            return false;
        }

        $cycle = $application->admissionCycle;
        $correction = $application->correctionRequests
            ->where('state', ApplicationCorrectionRequest::StateActive)
            ->sortByDesc('sequence')
            ->first();

        return $cycle?->opens_at !== null || $cycle?->closes_at !== null || $correction?->due_at !== null
            || $cycle?->requirements_due_at !== null || $cycle?->payment_due_at !== null || $cycle?->period_ends_at !== null;
    }

    /**
     * The login notice opens only when there is something to say: a real date, or an announcement.
     */
    public function shouldShowHomeNoticeModal(): bool
    {
        return $this->hasImportantDates($this->currentApplication()) || $this->schoolAnnouncements()->isNotEmpty();
    }

    public function getHeading(): string
    {
        $firstName = Auth::user()?->first_name ?: $this->currentApplication()?->first_name;

        return filled($firstName) ? "Hi, {$firstName}!" : 'Hi there!';
    }

    public function getSubheading(): string
    {
        return "Here's what's happening with your application.";
    }

    /**
     * Places the greeting and any header actions in a decorated card on Home only.
     */
    public function getHeader(): ?View
    {
        return view('filament.applicant.components.home-greeting', [
            'heading' => $this->getHeading(),
            'subheading' => $this->getSubheading(),
            'actions' => $this->getCachedHeaderActions(),
            'actionsAlignment' => $this->getHeaderActionsAlignment(),
        ]);
    }

    /**
     * The nearest deadline that applies to the Applicant now: an active correction, else the open intake's closing time.
     *
     * @return array{label: string, value: ?string, note: string}
     */
    public function applicationDeadline(?AdmissionApplication $application): array
    {
        $format = fn ($instant): string => $instant->timezone(config('app.display_timezone'))->format('M j, Y, g:i A');
        $deadlineStatus = $application instanceof AdmissionApplication ? $this->deadlineStatus($application) : ['status' => null, 'deadline' => null];
        if ($deadlineStatus['status'] === AdmissionDeadlineStatus::Closed) {
            return ['label' => 'Admission period ended', 'value' => $format($deadlineStatus['deadline']), 'note' => 'This application is closed.'];
        }
        if ($deadlineStatus['status'] === AdmissionDeadlineStatus::Expired) {
            return ['label' => 'Deadline passed', 'value' => $format($deadlineStatus['deadline']), 'note' => 'Contact Admissions support about a new deadline.'];
        }

        $correction = $application?->correctionRequests
            ->where('state', ApplicationCorrectionRequest::StateActive)
            ->sortByDesc('sequence')
            ->first();

        if ($correction?->due_at) {
            return [
                'label' => $correction->isOverdue() ? 'Correction overdue' : 'Correction due',
                'value' => $format($correction->due_at),
                'note' => $correction->isOverdue() ? 'Still accepted. Respond as soon as you can.' : null,
            ];
        }

        if (! $application instanceof AdmissionApplication) {
            $closesAt = AdmissionCycle::query()
                ->where('state', AdmissionCycle::StatePublished)
                ->where('opens_at', '<=', now())
                ->where('closes_at', '>', now())
                ->orderBy('closes_at')
                ->value('closes_at');

            return $closesAt
                ? ['label' => 'Applications close', 'value' => $format(Carbon::parse($closesAt)), 'note' => null]
                : ['label' => 'Applications close', 'value' => null, 'note' => 'No admission cycle is open right now.'];
        }

        if ($application->application_state === AdmissionApplication::StateDraft && $application->admissionCycle?->closes_at) {
            return app(ReadyApplicantProjectionQuery::class)->draftCycleIsOpen($application)
                ? ['label' => 'Applications close', 'value' => $format($application->admissionCycle->closes_at), 'note' => 'Submit before this time.']
                : ['label' => 'Applications closed', 'value' => $format($application->admissionCycle->closes_at), 'note' => 'This draft can no longer be submitted.'];
        }

        $closesAt = $application->admissionCycle?->closes_at;
        if ($application->application_state === AdmissionApplication::StateSubmitted && $closesAt?->isFuture()) {
            return ['label' => 'Applications close', 'value' => $format($closesAt), 'note' => $application->allowsSubmittedEdit()
                ? 'You can still update your application until review starts.'
                : null];
        }

        if ($application->application_state === AdmissionApplication::StateAdmitted) {
            $projection = app(ReadyApplicantProjectionQuery::class)->forApplication($application);
            $deadlines = app(AdmissionDeadlineStatus::class);
            $papersDue = $deadlines->requirementsDeadline($application);
            $paymentDue = $deadlines->paymentDeadline($application);

            if ($projection['clearance_result'] !== 'Cleared' && $papersDue?->isFuture()) {
                return ['label' => 'Physical papers due', 'value' => $format($papersDue), 'note' => 'Bring the papers listed in your checklist to the Registrar.'];
            }

            if ($projection['clearance_result'] === 'Cleared' && $paymentDue?->isFuture()) {
                return ['label' => 'Enrollment payment due', 'value' => $format($paymentDue), 'note' => 'Accounting must verify your payment by this time.'];
            }
        }

        return ['label' => 'Next deadline', 'value' => null, 'note' => 'Nothing is due from you right now.'];
    }

    /**
     * The preliminary copies the Applicant's requirement set asks for, with their current state, followed by the
     * official papers handed over on site. Submitted applications reuse the Requirements page rows so both pages agree.
     *
     * @return array{items: list<array{requirement_id: int, label: string, state: string, color: string, onsite: bool}>, url: ?string, linkLabel: string}
     */
    public function requirementChecklist(?AdmissionApplication $application): array
    {
        if (! $application instanceof AdmissionApplication) {
            return ['items' => [], 'url' => null, 'linkLabel' => ''];
        }

        if ($application->currentSubmissionVersion !== null) {
            $items = collect(app(Requirements::class)->preliminaryRows($application))
                ->map(fn (array $row): array => [
                    'requirement_id' => (int) $row['requirement']->id,
                    'label' => $row['requirement']->label,
                    'onsite' => false,
                    // The Registrar's remark for a copy that needs action, and where to fix it.
                    'note' => in_array($row['result'], [PreliminaryEvidenceReview::ResultActionNeeded, PreliminaryEvidenceReview::ResultUnderReview], true) && filled($row['instruction'])
                        ? (string) $row['instruction'] : null,
                    'fix_url' => ($row['action'] ?? false) ? Application::getUrl(['application' => $application->id]) : null,
                    ...match ($row['result']) {
                        PreliminaryEvidenceReview::ResultAccepted => ['state' => 'Accepted', 'color' => 'success'],
                        PreliminaryEvidenceReview::ResultActionNeeded => ['state' => 'Needs action', 'color' => 'warning'],
                        'NotSubmitted' => ['state' => 'Not submitted', 'color' => 'gray'],
                        default => ['state' => 'Submitted', 'color' => 'info'],
                    },
                ])->all();

            return [
                'items' => self::mergedChecklistItems([...$items, ...app(Requirements::class)->onSiteItems($application)]),
                'url' => Requirements::getUrl(['application' => $application->id]),
                'linkLabel' => 'View requirements',
            ];
        }

        try {
            $allRequirements = app(ResolveAdmissionRequirementSet::class)->forApplication($application)->requirements
                ->sortBy('display_order');
        } catch (ValidationException) {
            $allRequirements = collect();
        }

        $items = $allRequirements->where('requires_preliminary_evidence', true)
            ->map(fn (AdmissionRequirement $requirement): array => [
                'requirement_id' => (int) $requirement->id,
                'label' => $requirement->label,
                'onsite' => false,
                ...($application->evidenceVersions->contains('admission_requirement_id', $requirement->id)
                    ? ['state' => 'Uploaded', 'color' => 'info']
                    : ['state' => 'Not uploaded', 'color' => 'gray']),
            ])->values()->all();
        $onSiteItems = $allRequirements->filter(fn (AdmissionRequirement $requirement): bool => $requirement->isOnSite())
            ->map(fn (AdmissionRequirement $requirement): array => [
                'requirement_id' => (int) $requirement->id,
                'label' => $requirement->label,
                'onsite' => true,
                'state' => 'To be passed on-site',
                'color' => 'info',
            ])->values()->all();

        return ['items' => self::mergedChecklistItems([...$items, ...$onSiteItems]), 'url' => Application::getUrl(['application' => $application->id]), 'linkLabel' => 'Upload in your application'];
    }

    /**
     * One checklist row per requirement: a paper with both an online copy and an on-site hand-over shows both
     * states as badges on the same row (e.g. Accepted / To be passed on-site) instead of listing the paper twice.
     *
     * @param  list<array{requirement_id: int, label: string, state: string, color: string, onsite: bool}>  $items
     * @return list<array{requirement_id: int, label: string, state: string, color: string, onsite: bool, pending_onsite: bool, badges: list<array{state: string, color: string}>}>
     */
    private static function mergedChecklistItems(array $items): array
    {
        return collect($items)
            ->groupBy('requirement_id')
            ->map(function (Collection $entries): array {
                $badges = $entries->map(fn (array $entry): array => ['state' => $entry['state'], 'color' => $entry['color']])->values()->all();
                $color = collect($badges)->contains('color', 'warning') ? 'warning' : end($badges)['color'];

                return [...$entries->first(), 'state' => end($badges)['state'], 'color' => $color, 'onsite' => (bool) $entries->every('onsite'), 'pending_onsite' => $entries->last()['onsite'] && end($badges)['color'] !== 'success', 'badges' => $badges];
            })
            ->values()
            ->all();
    }

    /**
     * Effective school announcements, read from the same source as the public site.
     *
     * @return Collection<int, PublicNotice>
     */
    public function schoolAnnouncements(): Collection
    {
        return PublicNotice::query()->effective()->orderBy('display_order')->orderBy('id')->limit(3)->get();
    }

    /**
     * The published support contact for the Applicant's cycle, or the open cycle when no application exists.
     *
     * @return array{text: string, href: ?string}|null
     */
    public function supportContact(?AdmissionApplication $application): ?array
    {
        $contact = trim((string) ($application?->admissionCycle?->support_contact ?? AdmissionCycle::query()
            ->where('state', AdmissionCycle::StatePublished)
            ->where('opens_at', '<=', now())
            ->where('closes_at', '>', now())
            ->value('support_contact')));

        if ($contact === '') {
            return null;
        }

        $dialable = preg_replace('/[\s()-]/', '', $contact);

        // AC10: the published contact is always a link; text that is not an address, phone or HTTPS link opens school support.
        return [
            'text' => $contact,
            'href' => match (true) {
                filter_var($contact, FILTER_VALIDATE_EMAIL) !== false => 'mailto:'.$contact,
                filter_var($contact, FILTER_VALIDATE_URL) !== false && str_starts_with($contact, 'https://') => $contact,
                preg_match('/^\+?\d{7,15}$/', (string) $dialable) === 1 => 'tel:'.$dialable,
                default => route('home', ['modal' => 'support']),
            },
        ];
    }

    public function responsibleParty(AdmissionApplication $application): string
    {
        return app(ReadyApplicantProjectionQuery::class)->responsibleParty($application);
    }

    /**
     * The school's public contact channels, shown in the in-panel Support modal so a signed-in
     * applicant never gets bounced out to the public site just to ask for help.
     *
     * @return array{support: ?string, support_phone: string, support_phone_uri: string}
     */
    public function officialReferences(): array
    {
        return app(ApplicantEntryReadinessService::class)->officialReferences();
    }

    /** Fix what the Registrar named, by the correction's due date. */
    private function correctionNextAction(AdmissionApplication $application): string
    {
        $correction = $application->correctionRequests()->where('state', ApplicationCorrectionRequest::StateActive)->latest('sequence')->first();
        if ($correction === null) {
            return 'Fix the items the Registrar named, then submit again.';
        }

        return 'Fix what the Registrar asked'.($correction->due_at ? ' by '.$correction->due_at->timezone(config('app.display_timezone'))->format('M j, Y, g:i A') : '').': '.$correction->applicant_instruction;
    }

    /** @return list<string> Labels of current copies whose latest review is Action needed. */
    private function copiesNeedingAction(AdmissionApplication $application): array
    {
        return collect(app(Requirements::class)->preliminaryRows($application))
            ->filter(fn (array $row): bool => $row['result'] === PreliminaryEvidenceReview::ResultActionNeeded)
            ->map(fn (array $row): string => (string) $row['requirement']->label)
            ->values()
            ->all();
    }

    public function nextAction(AdmissionApplication $application): string
    {
        $deadline = $this->deadlineStatus($application);
        if ($deadline['status'] === AdmissionDeadlineStatus::Closed) {
            return 'Create a new account to apply when the next admission period opens.';
        }
        if ($deadline['status'] === AdmissionDeadlineStatus::Expired) {
            return 'Contact Admissions support to ask the Registrar for a new deadline.';
        }

        if ($application->application_state === AdmissionApplication::StateAdmitted) {
            $projection = app(ReadyApplicantProjectionQuery::class)->forApplication($application);
            if ($projection['ready'] ?? false) {
                return app(ReadyApplicantProjectionQuery::class)->applicantEnrollmentNextAction($application);
            }

            return 'Follow the Registrar instructions for external school checks and enrollment clearance.';
        }

        return match ($application->application_state) {
            AdmissionApplication::StateDraft => app(ReadyApplicantProjectionQuery::class)->draftNextAction($application),
            AdmissionApplication::StateActionNeeded => $this->correctionNextAction($application),
            AdmissionApplication::StateSubmitted => match (true) {
                $application->allowsSubmittedEdit() => 'Awaiting Registrar review. Open Application to commit a complete update before review starts.',
                $this->copiesNeedingAction($application) !== [] => 'The Registrar marked '.implode(', ', $this->copiesNeedingAction($application)).' for correction. You can upload a new copy once the Registrar sends the correction.',
                default => 'The Registrar is reviewing your application.',
            },
            AdmissionApplication::StateNotAdmitted => 'Read the Registrar\'s reason. This decision is final.',
            AdmissionApplication::StateWithdrawn => 'Contact the Registrar only if an authorized reopening is needed.',
            default => 'Review the current Application record.',
        };
    }

    public function enrollmentAvailability(?AdmissionApplication $application = null): array
    {
        $application ??= $this->currentApplication();

        return $application instanceof AdmissionApplication
            ? app(ReadyApplicantProjectionQuery::class)->enrollmentAvailability($application)
            : ['available' => false, 'status' => 'unavailable', 'explanation' => 'Term enrollment window is not available.', 'opens_at' => null, 'closes_at' => null];
    }

    public function canWithdraw(): bool
    {
        $application = $this->currentApplication();

        if (! $application instanceof AdmissionApplication
            || ! in_array($application->application_state, [
                AdmissionApplication::StateSubmitted,
                AdmissionApplication::StateActionNeeded,
                AdmissionApplication::StateAdmitted,
            ], true)) {
            return false;
        }

        return ! app(ReadyApplicantProjectionQuery::class)->registrationHasStarted($application)
            && $this->deadlineStatus($application)['status'] !== AdmissionDeadlineStatus::Closed;
    }

    public function failedNotification(): ?OperationalEvent
    {
        $application = $this->currentApplication();

        if (! $application instanceof AdmissionApplication) {
            return null;
        }

        return OperationalEvent::query()
            ->where('event_domain', OperationalEvent::DomainNotifications)
            ->where('related_record_type', AdmissionApplication::class)
            ->where('related_record_id', $application->id)
            ->where('status', OperationalEvent::StatusFailed)
            ->latest('failed_at')
            ->first();
    }

    /** @return array<string, mixed> */
    private function financeContext(): array
    {
        $actor = Auth::user();

        return $actor instanceof User
            ? app(StudentTermAccountPresenter::class)->forUser($actor)
            : ['available' => false];
    }
}
