<?php

namespace App\Filament\Applicant\Pages;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Enrollment\CancelRegistrationCase;
use App\Actions\Enrollment\ConfirmRegistrationProposal;
use App\Actions\Enrollment\StartRegistrationCase;
use App\Actions\Finance\SubmitPaymentEvidence;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\Enrollment;
use App\Models\OperationalEvent;
use App\Models\Program;
use App\Models\RegistrationProposalVersion;
use App\Models\Term;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Throwable;

class Dashboard extends BaseDashboard implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.applicant.pages.dashboard';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startRegistration')
                ->label('Start enrollment')
                ->icon('heroicon-o-play')
                ->visible(function (): bool {
                    $application = $this->currentApplication();

                    return $application instanceof AdmissionApplication
                        && ! $this->registrationCase() instanceof Enrollment
                        && app(ReadyApplicantProjectionQuery::class)->forApplication($application)['ready'];
                })
                ->schema([
                    Select::make('selection_basis')
                        ->label('Registration basis')
                        ->options([
                            Enrollment::SelectionStandardCurriculum => 'Standard Curriculum',
                            Enrollment::SelectionIndividuallyAdvised => 'Individually Advised',
                        ])
                        ->default(Enrollment::SelectionStandardCurriculum)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $application = $this->currentApplication();
                    $applicant = Auth::user();
                    abort_unless($application instanceof AdmissionApplication && $applicant instanceof User, 404);

                    app(StartRegistrationCase::class)->forReadyApplicant(
                        $application,
                        Term::query()->findOrFail($application->term_id),
                        $applicant,
                        (string) $data['selection_basis'],
                    );
                    Notification::make()
                        ->title('Enrollment started')
                        ->body('The Registrar can now prepare the exact-Term proposal. No Student identity was created yet.')
                        ->success()
                        ->send();
                }),
            Action::make('confirmRegistrationProposal')
                ->label('Confirm enrollment proposal')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->registrationProposalSummary())
                ->visible(fn (): bool => $this->registrationCase()?->currentProposalVersion?->state === RegistrationProposalVersion::StateIssued)
                ->action(function (): void {
                    $case = $this->registrationCase();
                    $applicant = Auth::user();
                    abort_unless($case instanceof Enrollment
                        && $case->currentProposalVersion instanceof RegistrationProposalVersion
                        && $applicant instanceof User, 404);

                    app(ConfirmRegistrationProposal::class)->execute($case->currentProposalVersion, $applicant);
                    Notification::make()
                        ->title('Enrollment proposal confirmed')
                        ->body('The Registrar can now protect the complete placement. Any material revision requires a new confirmation.')
                        ->success()
                        ->send();
                }),
            Action::make('cancelRegistration')
                ->label('Cancel enrollment')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(function (): bool {
                    $case = $this->registrationCase();

                    return $case instanceof Enrollment
                        && $case->canonical_outcome === Enrollment::OutcomeInProgress
                        && $case->currentProposalVersion?->confirmation === null;
                })
                ->schema([
                    Textarea::make('reason')->label('Reason')->required()->maxLength(2000),
                ])
                ->action(function (array $data): void {
                    $case = $this->registrationCase();
                    $applicant = Auth::user();
                    abort_unless($case instanceof Enrollment && $applicant instanceof User, 404);

                    try {
                        app(CancelRegistrationCase::class)->execute(
                            $case,
                            $applicant,
                            (string) $data['reason'],
                            $case->lock_version,
                        );
                        Notification::make()
                            ->title('Enrollment cancelled')
                            ->body('The Registration Case and its history remain available. Registrar authority is required to reopen it.')
                            ->success()
                            ->send();
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Enrollment was not cancelled')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
            Action::make('submitPaymentEvidence')
                ->label('Submit payment evidence')
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(function (): bool {
                    $case = $this->registrationCase();

                    return $case instanceof Enrollment
                        && $case->termAccount !== null
                        && $case->termAccount->state !== 'Cleared';
                })
                ->schema([
                    FileUpload::make('evidence')
                        ->label('Private payment evidence')
                        ->storeFiles(false)
                        ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                        ->maxSize(10240)
                        ->required(),
                    TextInput::make('claimed_amount')->numeric()->minValue(0.01)->prefix('PHP')->required(),
                    TextInput::make('payment_reference')->maxLength(255),
                ])
                ->action(function (array $data): void {
                    $case = $this->registrationCase();
                    $applicant = Auth::user();
                    $file = $data['evidence'] ?? null;
                    abort_unless($case instanceof Enrollment
                        && $case->termAccount !== null
                        && $applicant instanceof User
                        && $file instanceof UploadedFile, 404);

                    app(SubmitPaymentEvidence::class)->execute(
                        $case->termAccount,
                        $applicant,
                        $file,
                        (string) $data['claimed_amount'],
                        $data['payment_reference'] ?? null,
                    );
                    Notification::make()
                        ->title('Payment evidence submitted')
                        ->body('Accounting must verify and allocate this evidence before it can clear an obligation.')
                        ->success()
                        ->send();
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
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AdmissionApplication::query()
                ->canonical()
                ->with(['admissionCycle', 'program', 'currentSubmissionVersion'])
                ->where('user_id', Auth::id()))
            ->columns([
                TextColumn::make('application_reference')->label('Reference')->placeholder('Draft')->searchable(),
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
                    ->label('Path')
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('application_state')
                    ->label('State')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                TextColumn::make('updated_at')->label('Last activity')->dateTime()->sortable(),
            ])
            ->recordActions([
                Action::make('continue')
                    ->label('Continue')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (): string => Application::getUrl())
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
        return AdmissionApplication::query()
            ->canonical()
            ->with([
                'admissionCycle',
                'program',
                'currentSubmissionVersion.requirementSet.requirements',
                'evidenceVersions.preliminaryReviews',
                'credentialResults.requirement',
                'correctionRequests.items',
                'decisions',
                'events',
            ])
            ->where('user_id', Auth::id())
            ->latest('updated_at')
            ->first();
    }

    private function registrationCase(): ?Enrollment
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
        return match ($application->application_state) {
            AdmissionApplication::StateDraft,
            AdmissionApplication::StateActionNeeded => 'Applicant',
            AdmissionApplication::StateSubmitted,
            AdmissionApplication::StateAdmitted => 'Registrar',
            default => 'No active task',
        };
    }

    public function nextAction(AdmissionApplication $application): string
    {
        return match ($application->application_state) {
            AdmissionApplication::StateDraft => 'Complete and submit the five-step Application.',
            AdmissionApplication::StateActionNeeded => 'Respond only to the scoped correction items.',
            AdmissionApplication::StateSubmitted => 'Wait for Registrar review; monitor Requirements for an instruction.',
            AdmissionApplication::StateAdmitted => 'Complete the due official credential instructions.',
            AdmissionApplication::StateNotAdmitted => 'Review the retained decision explanation.',
            AdmissionApplication::StateWithdrawn => 'Contact the Registrar only if an authorized reopening is needed.',
            default => 'Review the current Application record.',
        };
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
}
