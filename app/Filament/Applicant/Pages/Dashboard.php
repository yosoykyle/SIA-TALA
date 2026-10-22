<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Admissions\DiscardAdmissionApplication;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Finance\StudentTermAccountPresenter;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\RegistrationProposalVersion;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
        if (request()->query('checkout') === 'success') {
            Notification::make()
                ->title('Payment confirmation pending')
                ->body('No balance changes until TALA verifies signed PayMongo evidence.')
                ->info()
                ->send();
        }

        if (request()->query('checkout') === 'cancelled') {
            Notification::make()
                ->title('Checkout cancelled — no payment was recorded from this return')
                ->body('Manual payment evidence remains available while no payment is confirmed.')
                ->warning()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('discardDraft')
                ->label('Discard draft')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->outlined()
                ->requiresConfirmation()
                ->modalSubmitActionLabel('Discard draft')
                ->modalDescription('Only this unsubmitted draft and its temporary uploads will be removed. Submitted history is never deleted.')
                ->visible(function (): bool {
                    $application = $this->currentApplication();

                    return $application instanceof AdmissionApplication
                        && $application->application_state === AdmissionApplication::StateDraft
                        && $application->current_submission_version_id === null;
                })
                ->action(function (): void {
                    $application = $this->currentApplication();
                    $applicant = Auth::user();
                    abort_unless($application instanceof AdmissionApplication && $applicant instanceof User, 404);

                    app(DiscardAdmissionApplication::class)->execute($application, $applicant);
                    Notification::make()->title('Draft discarded')->success()->send();
                    $this->redirect(self::getUrl());
                }),
            Action::make('openEnrollment')
                ->label('Open enrollment')
                ->icon('heroicon-o-academic-cap')
                ->url(fn (): string => \App\Filament\Applicant\Pages\Enrollment::getUrl())
                ->visible(function (): bool {
                    $application = $this->currentApplication();

                    return $this->registrationCase() instanceof Enrollment
                        || ($application instanceof AdmissionApplication && app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready']);
                }),
            Action::make('resendFailedNotification')
                ->label('Resend failed update')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->requiresConfirmation()
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
                }),
            Action::make('withdrawApplication')
                ->label('Withdraw application')
                ->icon('heroicon-o-archive-box-x-mark')
                ->color('danger')
                ->requiresConfirmation()
                ->modalDescription('The same Application reference and all submitted versions remain in history. Withdrawal is blocked once Clinic 4 registration starts.')
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
                }),
        ];

        $secondary = [];
        $primary = [];
        foreach ($actions as $action) {
            if (in_array($action->getName(), ['withdrawApplication', 'resendFailedNotification'], true)) {
                $secondary[] = $action;
            } else {
                $primary[] = $action;
            }
        }

        return [...$primary, ActionGroup::make($secondary)->label('More actions')->icon('heroicon-o-ellipsis-horizontal')];
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

                        return $program instanceof Program ? $program->name : 'Program not selected';
                    })
                    ->wrap(),
                TextColumn::make('application_path')
                    ->label('Student type')
                    ->formatStateUsing(fn (string $state): string => AdmissionCycle::studentTypeLabel($state)),
                TextColumn::make('application_state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
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
            ->emptyStateHeading('No application yet')
            ->emptyStateDescription('Start one application while a published Admission Cycle is open.');
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

        return 'Confirm proposal version '.$proposal->version.': '.$proposal->items
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

    public function statusLabel(string $state): string
    {
        return str($state)->headline()->toString();
    }

    public function statusColor(string $state): string
    {
        return match ($state) {
            AdmissionApplication::StateAdmitted => 'success',
            AdmissionApplication::StateActionNeeded => 'danger',
            AdmissionApplication::StateNotAdmitted,
            AdmissionApplication::StateWithdrawn => 'gray',
            default => 'info',
        };
    }

    public function responsibleParty(AdmissionApplication $application): string
    {
        return app(ReadyApplicantProjectionQuery::class)->responsibleParty($application);
    }

    public function nextAction(AdmissionApplication $application): string
    {
        if ($application->application_state === AdmissionApplication::StateAdmitted) {
            $projection = app(ReadyApplicantProjectionQuery::class)->forApplication($application);
            if ($projection['ready'] ?? false) {
                return app(ReadyApplicantProjectionQuery::class)->enrollmentNextAction($application);
            }

            return 'Follow the Registrar instructions for external school checks and enrollment clearance.';
        }

        return match ($application->application_state) {
            AdmissionApplication::StateDraft => app(ReadyApplicantProjectionQuery::class)->draftNextAction($application),
            AdmissionApplication::StateActionNeeded => 'Respond only to the scoped correction items.',
            AdmissionApplication::StateSubmitted => $application->allowsSubmittedEdit()
                ? 'Awaiting Registrar review. Open Application to commit a complete update before review starts.'
                : 'Wait for Registrar review; monitor Requirements for an instruction.',
            AdmissionApplication::StateNotAdmitted => 'Review the retained decision explanation.',
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

    private function canWithdraw(): bool
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

        return ! app(ReadyApplicantProjectionQuery::class)->registrationHasStarted($application);
    }

    private function failedNotification(): ?OperationalEvent
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
            : ['available' => false, 'checkout' => ['enabled' => false, 'reason' => 'Sign in to continue.']];
    }
}
