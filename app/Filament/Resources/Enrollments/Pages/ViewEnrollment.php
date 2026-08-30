<?php

namespace App\Filament\Resources\Enrollments\Pages;

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
use App\Actions\Enrollment\RecordRegistrationLateAuthority;
use App\Actions\Enrollment\RecordRegistrationSourceImpactReview;
use App\Actions\Enrollment\RegistrationNotificationLedger;
use App\Actions\Enrollment\RegistrationReadinessQuery;
use App\Actions\Enrollment\ReopenRegistrationCase;
use App\Actions\Enrollment\StudentUnitLoadService;
use App\Actions\Finance\CreateAssessmentFromPublishedFeePlan;
use App\Actions\Finance\CreateContextualFinanceExport;
use App\Actions\Finance\RecordApprovedCoverage;
use App\Actions\Finance\RecordAuthorizedIndividualAssessment;
use App\Actions\Finance\ReverseApprovedCoverage;
use App\Actions\Finance\ReversePaymentPosting;
use App\Actions\Finance\ReviewPaymentEvidence;
use App\Actions\Integrations\Payments\PayMongoCheckoutRecoveryService;
use App\Actions\Integrations\Payments\PayMongoReconciliationService;
use App\Actions\Scheduling\ResolveTimetableRevisionRegistrationImpact;
use App\Filament\Resources\Enrollments\EnrollmentResource;
use App\Models\ApprovedCoverage;
use App\Models\Assessment;
use App\Models\CourseEnrollment;
use App\Models\Enrollment;
use App\Models\FinanceExport;
use App\Models\OperationalEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\PaymentEvidenceVersion;
use App\Models\PublishedTimetableMeeting;
use App\Models\PublishedTimetableVersion;
use App\Models\RegistrationAdjustmentFinanceConfirmation;
use App\Models\RegistrationCaseEvent;
use App\Models\RegistrationLateAuthority;
use App\Models\RegistrationProposalVersion;
use App\Models\Section;
use App\Models\TimetableRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Throwable;

class ViewEnrollment extends ViewRecord
{
    protected static string $resource = EnrollmentResource::class;

    public function getRecord(): Enrollment
    {
        $record = parent::getRecord();
        abort_unless($record instanceof Enrollment, 404);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->primaryAction(),
            ActionGroup::make([
                $this->prepareProposalAction(),
                $this->recordGraduatingOverloadAuthorityAction(),
                $this->prepareAdjustmentProposalAction(),
                $this->issueProposalAction(),
                $this->assistedConfirmationAction(),
                $this->placeProposalAction(),
                $this->createAssessmentAction(),
                $this->individualAssessmentAction(),
                $this->coverageAction(),
                $this->reverseCoverageAction(),
                $this->downloadPaymentEvidenceAction(),
                $this->verifyPaymentEvidenceAction(),
                $this->retrievePayMongoCheckoutAction(),
                $this->confirmRecoveredPayMongoAction(),
                $this->rejectRecoveredPayMongoAction(),
                $this->retryPayMongoEventAction(),
                $this->linkUnknownPayMongoEventAction(),
                $this->confirmPayMongoExceptionAction(),
                $this->rejectPayMongoExceptionAction(),
                $this->reversePaymentAction(),
                $this->rejectPaymentEvidenceAction(),
                $this->exportAccountAction(),
                $this->resolveImpactReviewAction(),
                Action::make('resendOfficialEnrollmentEmail')
                    ->label('Resend failed enrollment email')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
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
                $this->confirmNoAdditionalCostAction(),
                $this->recordLateAuthorityAction(),
                $this->adjustRegistrationAction(),
                $this->dropCourseAction(),
                $this->cancelAction(),
                $this->recordLateReopenAuthorityAction(),
                $this->reopenAction(),
                Action::make('printCor')
                    ->label('Print current COR')
                    ->icon('heroicon-o-printer')
                    ->url(fn (): string => route('cor.print', $this->getRecord()))
                    ->openUrlInNewTab()
                    ->visible(fn (): bool => $this->getRecord()->current_cor_version_id !== null),
                Action::make('printCorHistory')
                    ->label('Print COR history')
                    ->icon('heroicon-o-document-duplicate')
                    ->visible(fn (): bool => $this->getRecord()->corVersions()->exists())
                    ->schema([
                        Select::make('version')
                            ->label('Immutable COR version')
                            ->options(fn (): array => $this->getRecord()->corVersions()
                                ->orderByDesc('version')
                                ->pluck('version', 'version')
                                ->mapWithKeys(fn (mixed $version): array => [(int) $version => 'COR version '.$version])
                                ->all())
                            ->required(),
                    ])
                    ->action(fn (array $data) => $this->redirect(route('cor.print', [
                        'enrollment' => $this->getRecord(),
                        'version' => (int) $data['version'],
                    ]))),
            ])
                ->label('More actions')
                ->icon('heroicon-o-ellipsis-horizontal')
                ->button()
                ->color('gray'),
        ];
    }

    private function primaryAction(): Action
    {
        return Action::make('finalizeOfficialEnrollment')
            ->label('Finalize official enrollment')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('TALA will lock and revalidate identity, proposal confirmation, protected placement, Accounting clearance, and the current Published Timetable. It then creates official course registrations, first Student access when needed, and immutable COR version 1 in one transaction.')
            ->schema([
                Textarea::make('remark')->label('Registrar remark')->maxLength(2000),
            ])
            ->authorize(fn (): bool => auth()->user()?->can('officiallyEnroll', $this->getRecord()) ?? false)
            ->visible(fn (): bool => app(RegistrationReadinessQuery::class)->for($this->getRecord())['ready'])
            ->action(function (array $data): void {
                $actor = $this->actor();

                try {
                    $this->record = app(FinalizeOfficialEnrollment::class)->execute(
                        $this->getRecord(),
                        $actor,
                        $data['remark'] ?? null,
                    );
                    Notification::make()
                        ->title('Official enrollment finalized')
                        ->body('Official registrations, Student access, downstream projections, and immutable COR were committed together.')
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    $this->failure('Official enrollment was not finalized', $exception);
                }
            });
    }

    private function prepareProposalAction(): Action
    {
        return Action::make('prepareProposal')
            ->label('Prepare or revise proposal')
            ->icon('heroicon-o-document-plus')
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress
                && ($this->actor()->can('confirmPlacement', $this->getRecord())))
            ->schema([
                Select::make('section_ids')
                    ->label('Exact Class Offerings')
                    ->options(fn (): array => $this->sectionOptions())
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText('Select the complete proposal. This creates an immutable successor and invalidates prior confirmation.'),
                Select::make('curriculum_position')
                    ->label('Applicable curriculum term')
                    ->options(fn (): array => app(StudentUnitLoadService::class)->positionOptions($this->getRecord()))
                    ->searchable()
                    ->preload()
                    ->required(fn (): bool => $this->getRecord()->selection_basis === Enrollment::SelectionIndividuallyAdvised)
                    ->visible(fn (): bool => $this->getRecord()->selection_basis === Enrollment::SelectionIndividuallyAdvised)
                    ->helperText('Confirm the exact curriculum position that defines the normal unit total. Additional or retake classes still count toward the requested total.'),
            ])
            ->action(function (array $data): void {
                try {
                    app(PrepareRegistrationProposal::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        array_map('intval', $data['section_ids']),
                        $this->getRecord()->lock_version,
                        curriculumPosition: $data['curriculum_position'] ?? null,
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Draft proposal prepared')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Proposal was not prepared', $exception);
                }
            });
    }

    private function recordGraduatingOverloadAuthorityAction(): Action
    {
        return Action::make('recordGraduatingOverloadAuthority')
            ->label('Record graduating overload authority')
            ->icon('heroicon-o-document-check')
            ->modalHeading('Record external graduating-overload authority')
            ->modalDescription(fn (): string => $this->proposalUnitLoadSummary())
            ->modalSubmitActionLabel('Record authority')
            ->authorize(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar))
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->getRecord()->currentProposalVersion?->state === RegistrationProposalVersion::StateDraft
                && data_get($this->getRecord()->currentProposalVersion->source_snapshot, 'unit_load.requires_graduating_overload') === true)
            ->schema([
                TextInput::make('authority_reference')
                    ->label('External authority reference')
                    ->required()
                    ->maxLength(255),
                DatePicker::make('authority_date')
                    ->label('External authority date')
                    ->required()
                    ->maxDate(now()),
                TextInput::make('evidence_reference')
                    ->label('Evidence reference')
                    ->required()
                    ->maxLength(255),
                Textarea::make('reason')
                    ->label('Recorded basis')
                    ->required()
                    ->maxLength(1000)
                    ->helperText('Record the externally approved result. This action does not grant the approval.'),
            ])
            ->action(function (array $data): void {
                try {
                    app(RecordGraduatingOverloadAuthority::class)->execute(
                        $this->getRecord()->currentProposalVersion,
                        $this->actor(),
                        $data,
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

    private function issueProposalAction(): Action
    {
        return Action::make('issueProposal')
            ->label('Issue proposal for confirmation')
            ->icon('heroicon-o-paper-airplane')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->getRecord()->currentProposalVersion?->state === RegistrationProposalVersion::StateDraft
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->action(function (): void {
                try {
                    app(IssueRegistrationProposal::class)->execute($this->getRecord()->currentProposalVersion, $this->actor());
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Proposal issued')->body('The learner can now review and confirm this exact version.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Proposal was not issued', $exception);
                }
            });
    }

    private function assistedConfirmationAction(): Action
    {
        return Action::make('recordAssistedConfirmation')
            ->label('Record assisted confirmation')
            ->icon('heroicon-o-user-plus')
            ->visible(fn (): bool => $this->getRecord()->currentProposalVersion?->state === RegistrationProposalVersion::StateIssued
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->schema([
                TextInput::make('evidence_reference')
                    ->label('Learner confirmation evidence')
                    ->required()
                    ->maxLength(255),
            ])
            ->action(function (array $data): void {
                try {
                    app(ConfirmRegistrationProposal::class)->execute(
                        $this->getRecord()->currentProposalVersion,
                        $this->getRecord()->credentialUser,
                        $this->actor(),
                        $data['evidence_reference'],
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Assisted confirmation recorded')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Confirmation was not recorded', $exception);
                }
            });
    }

    private function placeProposalAction(): Action
    {
        return Action::make('placeProposal')
            ->label('Protect placement')
            ->icon('heroicon-o-map-pin')
            ->visible(fn (): bool => $this->getRecord()->currentProposalVersion?->state === RegistrationProposalVersion::StateConfirmed
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->requiresConfirmation()
            ->modalDescription('TALA uses the exact institutional enrollment deadline configured for this Term. No per-learner deadline is invented.')
            ->action(function (): void {
                try {
                    app(PlaceRegistrationProposal::class)->execute(
                        $this->getRecord()->currentProposalVersion,
                        $this->actor(),
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Placement protected')->body('Capacity and conflicts were checked atomically.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Placement was not protected', $exception);
                }
            });
    }

    private function createAssessmentAction(): Action
    {
        return Action::make('createAssessment')
            ->label('Create assessment from Fee Plan')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && in_array($this->getRecord()->canonical_outcome, [
                    Enrollment::OutcomeInProgress,
                    Enrollment::OutcomeOfficiallyEnrolled,
                ], true))
            ->requiresConfirmation()
            ->action(function (): void {
                try {
                    app(CreateAssessmentFromPublishedFeePlan::class)->execute($this->getRecord(), $this->actor());
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Assessment created')->body('The immutable current Fee Plan was used.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Assessment was not created', $exception);
                }
            });
    }

    private function individualAssessmentAction(): Action
    {
        return Action::make('recordIndividualAssessment')
            ->label('Record authorized individual assessment')
            ->icon('heroicon-o-document-currency-dollar')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && in_array($this->getRecord()->canonical_outcome, [
                    Enrollment::OutcomeInProgress,
                    Enrollment::OutcomeOfficiallyEnrolled,
                ], true))
            ->schema([
                Select::make('category')->options(array_combine(Assessment::IndividualCategories, Assessment::IndividualCategories))->required(),
                TextInput::make('authority_reference')->required()->maxLength(255),
                DatePicker::make('authority_date')->required()->native(false),
                Repeater::make('charges')
                    ->schema([
                        TextInput::make('code')->required()->maxLength(40),
                        TextInput::make('label')->required()->maxLength(255),
                        TextInput::make('amount')->numeric()->minValue(0)->prefix('PHP')->required(),
                    ])
                    ->minItems(1)
                    ->columns(3),
                Repeater::make('obligations')
                    ->schema([
                        TextInput::make('code')->required()->maxLength(40),
                        TextInput::make('label')->required()->maxLength(255),
                        TextInput::make('purpose')->required()->maxLength(40),
                        TextInput::make('amount')->numeric()->minValue(0)->prefix('PHP')->required(),
                        DateTimePicker::make('due_at')->required()->seconds(false),
                        Toggle::make('required_for_enrollment')->label('Enrollment requirement'),
                    ])->minItems(1)->columns(3),
            ])
            ->action(function (array $data): void {
                try {
                    app(RecordAuthorizedIndividualAssessment::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        $data['category'],
                        $data['authority_reference'],
                        CarbonImmutable::parse($data['authority_date'], config('app.timezone')),
                        $data['charges'],
                        $data['obligations'],
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Authorized assessment recorded')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Assessment was not recorded', $exception);
                }
            });
    }

    private function coverageAction(): Action
    {
        return Action::make('recordApprovedCoverage')
            ->label('Record approved coverage')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->obligationOptions() !== [])
            ->schema([
                Select::make('obligation_id')->options(fn (): array => $this->obligationOptions())->required()->searchable(),
                Select::make('supersedes_coverage_id')->label('Supersede active coverage')->options(fn (): array => $this->activeCoverageOptions())->searchable(),
                Select::make('category')->options(array_combine(ApprovedCoverage::Categories, ApprovedCoverage::Categories))->required(),
                TextInput::make('safe_source_description')->required()->maxLength(255),
                TextInput::make('amount')->numeric()->minValue(0.01)->prefix('PHP')->required(),
                TextInput::make('authority_reference')->required()->maxLength(255),
                DatePicker::make('authority_date')->required()->native(false),
                DatePicker::make('effective_date')->required()->native(false),
            ])
            ->action(function (array $data): void {
                $account = $this->getRecord()->termAccount()->firstOrFail();
                $obligation = $account->assessments()->where('state', 'active')->latest('version')->firstOrFail()
                    ->obligations()->findOrFail((int) $data['obligation_id']);

                try {
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
                            'supersedes_coverage_id' => $data['supersedes_coverage_id'] ?? null,
                        ],
                        $this->actor(),
                    );
                    Notification::make()->title('Approved coverage recorded')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Coverage was not recorded', $exception);
                }
            });
    }

    private function verifyPaymentEvidenceAction(): Action
    {
        return Action::make('verifyPaymentEvidence')
            ->label('Verify payment evidence')
            ->icon('heroicon-o-check-circle')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->submittedEvidenceOptions() !== [])
            ->schema([
                Select::make('evidence_id')->options(fn (): array => $this->submittedEvidenceOptions())->required()->searchable(),
                TextInput::make('actual_verified_amount')->numeric()->minValue(0.01)->prefix('PHP')->required(),
                TextInput::make('external_check_reference')->label('Independent verification result')->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                try {
                    app(ReviewPaymentEvidence::class)->verify(
                        PaymentEvidenceVersion::query()->findOrFail((int) $data['evidence_id']),
                        $this->actor(),
                        (string) $data['actual_verified_amount'],
                        $data['external_check_reference'],
                    );
                    Notification::make()->title('Payment evidence verified')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Evidence was not verified', $exception);
                }
            });
    }

    private function retrievePayMongoCheckoutAction(): Action
    {
        return Action::make('retrievePayMongoCheckout')
            ->label('Retrieve pending PayMongo checkout')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->recoverablePayMongoAttemptOptions() !== [])
            ->schema([
                Select::make('payment_attempt_id')
                    ->label('Exact Payment Attempt')
                    ->options(fn (): array => $this->recoverablePayMongoAttemptOptions())
                    ->required()
                    ->searchable(),
            ])
            ->modalDescription('TALA retrieves only the selected Checkout Session. A paid result remains review evidence until Accounting confirms the exact immutable targets.')
            ->action(function (array $data): void {
                try {
                    $result = app(PayMongoCheckoutRecoveryService::class)->recover(
                        (int) $data['payment_attempt_id'],
                        $this->actor(),
                    );
                    Notification::make()
                        ->title('PayMongo checkout retrieved')
                        ->body('Safe provider state: '.$result['status'])
                        ->success()
                        ->send();
                } catch (Throwable $exception) {
                    $this->failure('PayMongo checkout was not retrieved', $exception);
                }
            });
    }

    private function confirmRecoveredPayMongoAction(): Action
    {
        return Action::make('confirmRecoveredPayMongo')
            ->label('Confirm recovered PayMongo payment')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->recoveredPayMongoEventOptions() !== [])
            ->schema([
                Select::make('event_id')->label('Recovered evidence')->options(fn (): array => $this->recoveredPayMongoEventOptions())->required(),
                Textarea::make('reason')->label('Accounting decision basis')->minLength(5)->maxLength(1000)->required(),
            ])
            ->modalDescription('Confirmation posts only when the provider evidence still matches the exact Term Account snapshot. A later signed event becomes a no-op.')
            ->action(function (array $data): void {
                try {
                    app(PayMongoCheckoutRecoveryService::class)->confirm(
                        (int) $data['event_id'],
                        (string) $data['reason'],
                        $this->actor(),
                    );
                    Notification::make()->title('Recovered PayMongo payment posted')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Recovered PayMongo payment was not posted', $exception);
                }
            });
    }

    private function rejectRecoveredPayMongoAction(): Action
    {
        return Action::make('rejectRecoveredPayMongo')
            ->label('Reject recovered PayMongo evidence')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->recoveredPayMongoEventOptions() !== [])
            ->schema([
                Select::make('event_id')->label('Recovered evidence')->options(fn (): array => $this->recoveredPayMongoEventOptions())->required(),
                Textarea::make('reason')->label('Safe rejection reason')->minLength(5)->maxLength(1000)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(PayMongoCheckoutRecoveryService::class)->reject(
                        (int) $data['event_id'],
                        (string) $data['reason'],
                        $this->actor(),
                    );
                    Notification::make()->title('Recovered evidence rejected')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Recovered evidence was not rejected', $exception);
                }
            });
    }

    private function retryPayMongoEventAction(): Action
    {
        return Action::make('retryPayMongoEvent')
            ->label('Retry failed PayMongo event')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->failedPayMongoEventOptions() !== [])
            ->schema([
                Select::make('event_id')->label('Failed event')->options(fn (): array => $this->failedPayMongoEventOptions())->required(),
                Textarea::make('reason')->label('Retry reason')->minLength(5)->maxLength(1000)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(PayMongoReconciliationService::class)->reprocess(
                        (int) $data['event_id'],
                        (string) $data['reason'],
                        $this->actor(),
                    );
                    Notification::make()->title('PayMongo event queued again')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('PayMongo event was not queued', $exception);
                }
            });
    }

    private function linkUnknownPayMongoEventAction(): Action
    {
        return Action::make('linkUnknownPayMongoEvent')
            ->label('Link unknown PayMongo reference')
            ->icon('heroicon-o-link')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->unknownPayMongoEventOptions() !== []
                && $this->activePayMongoAttemptOptions() !== [])
            ->schema([
                Select::make('event_id')->label('Unknown-reference event')->options(fn (): array => $this->unknownPayMongoEventOptions())->required(),
                Select::make('payment_attempt_id')->label('Exact account attempt')->options(fn (): array => $this->activePayMongoAttemptOptions())->required(),
                Textarea::make('reason')->label('Linkage evidence')->minLength(5)->maxLength(1000)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(PayMongoReconciliationService::class)->linkAndReprocess(
                        (int) $data['event_id'],
                        (int) $data['payment_attempt_id'],
                        (string) $data['reason'],
                        $this->actor(),
                    );
                    Notification::make()->title('PayMongo event linked and queued')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('PayMongo event was not linked', $exception);
                }
            });
    }

    private function confirmPayMongoExceptionAction(): Action
    {
        return Action::make('confirmPayMongoException')
            ->label('Confirm exact PayMongo exception')
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->confirmablePayMongoEventOptions() !== [])
            ->schema([
                Select::make('event_id')->label('Exact review event')->options(fn (): array => $this->confirmablePayMongoEventOptions())->required(),
                Textarea::make('reason')->label('Confirmation evidence')->minLength(5)->maxLength(1000)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(PayMongoReconciliationService::class)->confirm(
                        (int) $data['event_id'],
                        (string) $data['reason'],
                        $this->actor(),
                    );
                    Notification::make()->title('PayMongo exception confirmed')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('PayMongo exception was not confirmed', $exception);
                }
            });
    }

    private function rejectPayMongoExceptionAction(): Action
    {
        return Action::make('rejectPayMongoException')
            ->label('Reject PayMongo exception')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->rejectablePayMongoEventOptions() !== [])
            ->schema([
                Select::make('event_id')->label('Review event')->options(fn (): array => $this->rejectablePayMongoEventOptions())->required(),
                Textarea::make('reason')->label('Safe rejection reason')->minLength(5)->maxLength(1000)->required(),
            ])
            ->action(function (array $data): void {
                try {
                    app(PayMongoReconciliationService::class)->reject(
                        (int) $data['event_id'],
                        (string) $data['reason'],
                        $this->actor(),
                    );
                    Notification::make()->title('PayMongo exception rejected')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('PayMongo exception was not rejected', $exception);
                }
            });
    }

    private function reverseCoverageAction(): Action
    {
        return Action::make('reverseApprovedCoverage')
            ->label('Reverse approved coverage')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $this->activeCoverageOptions() !== [])
            ->schema([
                Select::make('coverage_id')->options(fn (): array => $this->activeCoverageOptions())->required(),
                TextInput::make('authority_reference')->required()->maxLength(255),
                Textarea::make('safe_reason')->required()->maxLength(1000),
            ])->action(function (array $data): void {
                try {
                    app(ReverseApprovedCoverage::class)->execute(
                        ApprovedCoverage::query()->findOrFail((int) $data['coverage_id']),
                        $this->actor(), $data['authority_reference'], $data['safe_reason'],
                    );
                    Notification::make()->title('Approved coverage reversed')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Coverage was not reversed', $exception);
                }
            });
    }

    private function reversePaymentAction(): Action
    {
        return Action::make('reversePaymentPosting')
            ->label('Reverse verified payment')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $this->reversiblePaymentOptions() !== [])
            ->schema([
                Select::make('payment_id')->options(fn (): array => $this->reversiblePaymentOptions())->required(),
                Select::make('paymongo_event_id')
                    ->label('Matching PayMongo refund or reversal evidence')
                    ->options(fn (): array => $this->refundPayMongoEventOptions())
                    ->helperText('Select the provider event when this reversal resolves PayMongo refund evidence.'),
                TextInput::make('authority_reference')->required()->maxLength(255),
                Textarea::make('safe_reason')->required()->maxLength(1000),
            ])->action(function (array $data): void {
                try {
                    $payment = Payment::query()->findOrFail((int) $data['payment_id']);
                    $reversal = app(ReversePaymentPosting::class)->execute(
                        $payment,
                        $this->actor(), $data['authority_reference'], $data['safe_reason'],
                    );
                    if (isset($data['paymongo_event_id'])) {
                        app(PayMongoReconciliationService::class)->recordRefundReversal(
                            (int) $data['paymongo_event_id'],
                            $payment,
                            $reversal,
                            (string) $data['safe_reason'],
                            $this->actor(),
                        );
                    }
                    Notification::make()->title('Payment reversal recorded')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Payment was not reversed', $exception);
                }
            });
    }

    private function exportAccountAction(): Action
    {
        return Action::make('exportTermAccount')
            ->label('Export verified payments')
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting) && $this->getRecord()->termAccount !== null)
            ->schema([
                Textarea::make('purpose')->required()->maxLength(1000),
                Select::make('state')
                    ->label('Current state')
                    ->options([
                        Payment::StatePosted => 'Posted',
                        Payment::StateReversal => 'Reversal',
                    ]),
                DatePicker::make('from')->label('Verified from')->native(false),
                DatePicker::make('until')->label('Verified until')->native(false),
            ])
            ->action(function (array $data): void {
                $account = $this->getRecord()->termAccount()->firstOrFail();
                $export = app(CreateContextualFinanceExport::class)->createVerifiedPayments(
                    $this->actor(),
                    $account,
                    (string) $data['purpose'],
                    [
                        'state' => $data['state'] ?? null,
                        'from' => $data['from'] ?? null,
                        'until' => $data['until'] ?? null,
                    ],
                );

                if ($export->outcome === FinanceExport::OutcomeNoRows) {
                    Notification::make()->title('No matching verified payments')->info()->send();

                    return;
                }

                $this->redirect(route('finance.exports.download', $export));
            });
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

    private function rejectPaymentEvidenceAction(): Action
    {
        return Action::make('rejectPaymentEvidence')
            ->label('Reject payment evidence')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleAccounting)
                && $this->submittedEvidenceOptions() !== [])
            ->schema([
                Select::make('evidence_id')->options(fn (): array => $this->submittedEvidenceOptions())->required()->searchable(),
                Textarea::make('safe_reason')->required()->maxLength(1000),
            ])
            ->action(function (array $data): void {
                try {
                    app(ReviewPaymentEvidence::class)->reject(
                        PaymentEvidenceVersion::query()->findOrFail((int) $data['evidence_id']),
                        $this->actor(),
                        $data['safe_reason'],
                    );
                    Notification::make()->title('Payment evidence rejected')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Evidence was not rejected', $exception);
                }
            });
    }

    private function resolveImpactReviewAction(): Action
    {
        return Action::make('resolveImpactReview')
            ->label('Resolve source-impact review')
            ->icon('heroicon-o-clipboard-document-check')
            ->visible(fn (): bool => $this->actor()->hasRole(User::StaffRoleRegistrar)
                && $this->openImpactReviewOptions() !== [])
            ->schema([
                Select::make('event_id')->options(fn (): array => $this->openImpactReviewOptions())->live()->required(),
                Select::make('timetable_outcome')
                    ->label('Validated timetable outcome')
                    ->options([
                        ResolveTimetableRevisionRegistrationImpact::OutcomeRetainedWithAcknowledgement => 'Retain placement with attributable acknowledgement',
                        ResolveTimetableRevisionRegistrationImpact::OutcomeRegistrationChanged => 'Registration proposal or official course changed',
                        ResolveTimetableRevisionRegistrationImpact::OutcomeCaseCancelled => 'Registration Case cancelled',
                    ])
                    ->visible(fn (Get $get): bool => $this->isTimetableImpactEvent($get('event_id')))
                    ->required(fn (Get $get): bool => $this->isTimetableImpactEvent($get('event_id'))),
                TextInput::make('evidence_reference')
                    ->label('Outcome evidence reference')
                    ->visible(fn (Get $get): bool => $this->isTimetableImpactEvent($get('event_id')))
                    ->required(fn (Get $get): bool => $this->isTimetableImpactEvent($get('event_id')))
                    ->maxLength(255),
                Textarea::make('outcome')
                    ->visible(fn (Get $get): bool => ! $this->isTimetableImpactEvent($get('event_id')))
                    ->required(fn (Get $get): bool => ! $this->isTimetableImpactEvent($get('event_id')))
                    ->maxLength(2000),
            ])
            ->action(function (array $data): void {
                try {
                    $event = RegistrationCaseEvent::query()->findOrFail((int) $data['event_id']);
                    if ($event->event_type === 'TimetableRevisionImpactReviewOpened') {
                        $revisionId = (int) (string) str((string) $event->authority_reference)->after('timetable-revision:');
                        app(ResolveTimetableRevisionRegistrationImpact::class)->execute(
                            TimetableRevision::query()->findOrFail($revisionId),
                            $this->getRecord(),
                            $event,
                            $this->actor(),
                            (string) $data['timetable_outcome'],
                            (string) $data['evidence_reference'],
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

    private function adjustRegistrationAction(): Action
    {
        return Action::make('adjustRegistration')
            ->label('Adjust official registration')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(function (): bool {
                $proposal = $this->getRecord()->currentProposalVersion;

                return $this->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                    && $proposal instanceof RegistrationProposalVersion
                    && $proposal->purpose === RegistrationProposalVersion::PurposeAdjustment
                    && $proposal->state === RegistrationProposalVersion::StateConfirmed
                    && $this->actor()->can('confirmPlacement', $this->getRecord());
            })
            ->schema([
                Select::make('financial_effect')->options([
                    'Increase' => 'Increase — cleared successor assessment required',
                    'NoAdditionalCost' => 'No additional cost',
                ])->live()->required(),
                Select::make('finance_confirmation_id')
                    ->label('Accounting no-additional-cost confirmation')
                    ->options(fn (): array => RegistrationAdjustmentFinanceConfirmation::query()
                        ->where('enrollment_id', $this->getRecord()->id)
                        ->whereNull('consumed_at')
                        ->latest('confirmed_at')
                        ->get()
                        ->mapWithKeys(fn (RegistrationAdjustmentFinanceConfirmation $confirmation): array => [
                            $confirmation->id => $confirmation->authority_reference,
                        ])->all())
                    ->visible(fn (Get $get): bool => $get('financial_effect') === RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost)
                    ->required(fn (Get $get): bool => $get('financial_effect') === RegistrationAdjustmentFinanceConfirmation::EffectNoAdditionalCost),
                TextInput::make('authority_reference')->required()->maxLength(255),
                Select::make('late_authority_id')
                    ->label('Exact late-adjustment authority')
                    ->options(fn (): array => RegistrationLateAuthority::query()
                        ->where('enrollment_id', $this->getRecord()->id)
                        ->where('action_type', RegistrationLateAuthority::ActionAdjustment)
                        ->whereNull('consumed_at')
                        ->orderByDesc('recorded_at')
                        ->pluck('authority_reference', 'id')
                        ->all())
                    ->helperText('Leave empty while the Add / Drop / Adjustment window is open.'),
            ])
            ->action(function (array $data): void {
                try {
                    $latestAssessment = $this->getRecord()->termAccount?->assessments()
                        ->where('state', 'active')
                        ->latest('version')
                        ->first();
                    app(ApplyRegistrationAdjustment::class)->execute(
                        $this->getRecord(),
                        $this->getRecord()->currentProposalVersion,
                        $this->actor(),
                        $data['financial_effect'],
                        $data['authority_reference'],
                        $latestAssessment,
                        isset($data['late_authority_id'])
                            ? RegistrationLateAuthority::query()->findOrFail((int) $data['late_authority_id'])
                            : null,
                        isset($data['finance_confirmation_id'])
                            ? RegistrationAdjustmentFinanceConfirmation::query()->findOrFail((int) $data['finance_confirmation_id'])
                            : null,
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Official registration adjusted')->body('A successor course fact and COR version were created; prior history remains immutable.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Registration was not adjusted', $exception);
                }
            });
    }

    private function prepareAdjustmentProposalAction(): Action
    {
        return Action::make('prepareAdjustmentProposal')
            ->label('Prepare enrollment adjustment')
            ->icon('heroicon-o-document-plus')
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->schema([
                Select::make('change_type')
                    ->options(['Add' => 'Add one course', 'Replace' => 'Replace or change one current course/class'])
                    ->live()
                    ->required(),
                Select::make('current_course_enrollment_id')
                    ->label('Current official course/class')
                    ->options(fn (): array => $this->currentCourseOptions())
                    ->visible(fn (Get $get): bool => $get('change_type') === 'Replace')
                    ->required(fn (Get $get): bool => $get('change_type') === 'Replace'),
                Select::make('replacement_section_id')
                    ->label('New exact-Term Class Offering')
                    ->options(fn (): array => $this->sectionOptions())
                    ->searchable()
                    ->required(),
            ])
            ->modalDescription('This creates an immutable full post-change proposal. The learner must confirm that exact version before Registrar can apply it.')
            ->action(function (array $data): void {
                try {
                    $current = CourseEnrollment::query()
                        ->where('enrollment_id', $this->getRecord()->id)
                        ->where('is_current', true)
                        ->where('status', CourseEnrollment::StatusActive)
                        ->get();
                    $sectionIds = $current->pluck('section_id');
                    if (($data['change_type'] ?? null) === 'Replace') {
                        $before = $current->firstWhere('id', (int) $data['current_course_enrollment_id']);
                        if (! $before instanceof CourseEnrollment) {
                            throw ValidationException::withMessages([
                                'current_course_enrollment_id' => 'Select a current official course from this Registration Case.',
                            ]);
                        }
                        $sectionIds = $sectionIds->reject(fn (mixed $id): bool => (int) $id === (int) $before->section_id);
                    }
                    $sectionIds->push((int) $data['replacement_section_id']);
                    app(PrepareRegistrationProposal::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        $sectionIds->unique()->values()->all(),
                        $this->getRecord()->lock_version,
                        RegistrationProposalVersion::PurposeAdjustment,
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Adjustment proposal prepared')->body('Issue it for learner confirmation before applying the change.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Adjustment proposal was not prepared', $exception);
                }
            });
    }

    private function confirmNoAdditionalCostAction(): Action
    {
        return Action::make('confirmNoAdditionalCost')
            ->label('Confirm no additional cost')
            ->icon('heroicon-o-banknotes')
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                && $this->actor()->hasRole(User::StaffRoleAccounting))
            ->schema([
                Select::make('course_enrollment_id')->label('Current official course')->options(fn (): array => $this->currentCourseOptions())
                    ->helperText('Leave empty for a course Add.'),
                Select::make('replacement_section_id')->label('Replacement Class Offering')->options(fn (): array => $this->sectionOptions())->required()->searchable(),
                TextInput::make('authority_reference')->label('Accounting authority reference')->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                try {
                    app(RecordRegistrationAdjustmentFinanceConfirmation::class)->execute(
                        $this->getRecord(),
                        isset($data['course_enrollment_id'])
                            ? CourseEnrollment::query()->findOrFail((int) $data['course_enrollment_id'])
                            : null,
                        Section::query()->findOrFail((int) $data['replacement_section_id']),
                        $this->actor(),
                        $data['authority_reference'],
                    );
                    Notification::make()->title('No-additional-cost classification confirmed')->body('Registrar may now apply the matching authorized adjustment.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Accounting confirmation was not recorded', $exception);
                }
            });
    }

    private function dropCourseAction(): Action
    {
        return Action::make('dropCourse')
            ->label('Record Course Drop')
            ->icon('heroicon-o-minus-circle')
            ->color('danger')
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->schema([
                Select::make('course_enrollment_id')->label('Current official course')->options(fn (): array => $this->currentCourseOptions())->required(),
                Textarea::make('reason')->required()->maxLength(2000),
                TextInput::make('authority_reference')->required()->maxLength(255),
                Select::make('late_authority_id')
                    ->label('Exact late Course Drop authority')
                    ->options(fn (): array => RegistrationLateAuthority::query()
                        ->where('enrollment_id', $this->getRecord()->id)
                        ->where('action_type', RegistrationLateAuthority::ActionCourseDrop)
                        ->whereNull('consumed_at')
                        ->orderByDesc('recorded_at')
                        ->pluck('authority_reference', 'id')
                        ->all())
                    ->helperText('Leave empty while the Add / Drop / Adjustment window is open.'),
            ])
            ->action(function (array $data): void {
                try {
                    app(RecordCourseDrop::class)->execute(
                        $this->getRecord(),
                        CourseEnrollment::query()->findOrFail((int) $data['course_enrollment_id']),
                        $this->actor(),
                        $data['reason'],
                        $data['authority_reference'],
                        isset($data['late_authority_id'])
                            ? RegistrationLateAuthority::query()->findOrFail((int) $data['late_authority_id'])
                            : null,
                    );
                    $this->record = $this->getRecord()->refresh();
                    Notification::make()->title('Course Drop recorded')->body('Accounting review is now required; prior COR remains historical.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Course Drop was not recorded', $exception);
                }
            });
    }

    private function cancelAction(): Action
    {
        return Action::make('cancelCase')
            ->label('Cancel registration case')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeInProgress
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->schema([Textarea::make('reason')->required()->maxLength(2000)])
            ->action(function (array $data): void {
                try {
                    $this->record = app(CancelRegistrationCase::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        $data['reason'],
                        $this->getRecord()->lock_version,
                    );
                    Notification::make()->title('Registration Case cancelled')->body('Capacity was released and history was preserved.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Registration Case was not cancelled', $exception);
                }
            });
    }

    private function recordLateAuthorityAction(): Action
    {
        return Action::make('recordLateAuthority')
            ->label('Record exact late authority')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => $this->getRecord()->canonical_outcome === Enrollment::OutcomeOfficiallyEnrolled
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->schema([
                Select::make('action_type')
                    ->options([
                        RegistrationLateAuthority::ActionAdjustment => 'Registration Adjustment',
                        RegistrationLateAuthority::ActionCourseDrop => 'Course Drop',
                    ])
                    ->live()
                    ->required(),
                Select::make('before_course_enrollment_id')
                    ->label('Current official course')
                    ->options(fn (): array => $this->currentCourseOptions())
                    ->required(fn (Get $get): bool => $get('action_type') === RegistrationLateAuthority::ActionCourseDrop)
                    ->helperText('Leave empty only for an exact course Add.'),
                Select::make('after_section_id')
                    ->label('Exact replacement class')
                    ->options(fn (): array => $this->sectionOptions())
                    ->searchable()
                    ->visible(fn (Get $get): bool => $get('action_type') === RegistrationLateAuthority::ActionAdjustment)
                    ->required(fn (Get $get): bool => $get('action_type') === RegistrationLateAuthority::ActionAdjustment),
                TextInput::make('approving_office')->required()->maxLength(120),
                TextInput::make('authority_reference')->required()->maxLength(255),
                DatePicker::make('authority_date')->required(),
                Textarea::make('reason')->required()->maxLength(2000),
                DateTimePicker::make('effective_at')->required(),
                TextInput::make('learner_acknowledgement_basis')->required()->maxLength(255),
                TextInput::make('source_academic_decision')->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                try {
                    app(RecordRegistrationLateAuthority::class)->execute(
                        $this->getRecord(),
                        isset($data['before_course_enrollment_id'])
                            ? CourseEnrollment::query()->findOrFail((int) $data['before_course_enrollment_id'])
                            : null,
                        isset($data['after_section_id'])
                            ? Section::query()->findOrFail((int) $data['after_section_id'])
                            : null,
                        $this->actor(),
                        (string) $data['action_type'],
                        (string) $data['approving_office'],
                        (string) $data['authority_reference'],
                        CarbonImmutable::parse($data['authority_date']),
                        (string) $data['reason'],
                        CarbonImmutable::parse($data['effective_at']),
                        (string) $data['learner_acknowledgement_basis'],
                        (string) $data['source_academic_decision'],
                    );
                    Notification::make()->title('Late authority recorded')->body('It changes no enrollment until the matching guarded action consumes it.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Late authority was not recorded', $exception);
                }
            });
    }

    private function reopenAction(): Action
    {
        return Action::make('reopenCase')
            ->label('Reopen registration case')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (): bool => in_array($this->getRecord()->canonical_outcome, Enrollment::reopenableOutcomes(), true)
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->schema([
                Textarea::make('reason')->required()->maxLength(2000),
                TextInput::make('authority_reference')->required()->maxLength(255),
                Select::make('late_authority_event_id')
                    ->label('Exact late-enrollment authority')
                    ->options(fn (): array => RegistrationCaseEvent::query()
                        ->where('enrollment_id', $this->getRecord()->id)
                        ->where('event_type', RecordLateEnrollmentReopenAuthority::EventType)
                        ->orderByDesc('recorded_at')
                        ->pluck('authority_reference', 'id')
                        ->all())
                    ->helperText('Leave empty only while the ordinary Enrollment window is still open.'),
            ])
            ->modalDescription('The same case and history are retained. Prior proposal, confirmation, seat, assessment, clearance, and eligibility facts are not restored.')
            ->action(function (array $data): void {
                try {
                    $this->record = app(ReopenRegistrationCase::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        $data['reason'],
                        $data['authority_reference'],
                        $this->getRecord()->lock_version,
                        isset($data['late_authority_event_id'])
                            ? RegistrationCaseEvent::query()->findOrFail((int) $data['late_authority_event_id'])
                            : null,
                    );
                    Notification::make()->title('Registration Case reopened')->body('All five checkpoints must be established again from current sources.')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Registration Case was not reopened', $exception);
                }
            });
    }

    private function recordLateReopenAuthorityAction(): Action
    {
        return Action::make('recordLateReopenAuthority')
            ->label('Record late-enrollment reopen authority')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => in_array($this->getRecord()->canonical_outcome, Enrollment::reopenableOutcomes(), true)
                && $this->actor()->can('confirmPlacement', $this->getRecord()))
            ->schema([
                TextInput::make('authority_reference')->required()->maxLength(255),
                Textarea::make('reason')->required()->maxLength(2000),
            ])
            ->modalDescription('This records authority for this exact learner, Registration Case, and Term. It does not reopen or restore any checkpoint by itself.')
            ->action(function (array $data): void {
                try {
                    app(RecordLateEnrollmentReopenAuthority::class)->execute(
                        $this->getRecord(),
                        $this->actor(),
                        (string) $data['authority_reference'],
                        (string) $data['reason'],
                    );
                    Notification::make()->title('Late-enrollment authority recorded')->success()->send();
                } catch (Throwable $exception) {
                    $this->failure('Late-enrollment authority was not recorded', $exception);
                }
            });
    }

    /** @return array<int, string> */
    private function sectionOptions(): array
    {
        $timetableId = PublishedTimetableVersion::query()
            ->where('term_id', $this->getRecord()->term_id)
            ->where('state', PublishedTimetableVersion::StatePublished)
            ->latest('version')
            ->value('id');

        if ($timetableId === null) {
            return [];
        }

        $sectionIds = PublishedTimetableMeeting::query()
            ->where('published_timetable_version_id', $timetableId)
            ->distinct()
            ->pluck('section_id');

        return Section::query()
            ->with('termOffering.curriculumEntry.courseSpecification.course')
            ->whereIn('id', $sectionIds)
            ->where('state', Section::StateOpen)
            ->whereHas('termOffering', fn (Builder $query): Builder => $query->where('term_id', $this->getRecord()->term_id))
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (Section $section): array => [
                $section->id => collect([
                    $section->termOffering?->course()?->code,
                    $section->termOffering?->courseSpecification()?->title,
                    $section->code,
                ])->filter()->implode(' — '),
            ])
            ->all();
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
    private function obligationOptions(): array
    {
        $account = $this->getRecord()->termAccount()->first();
        $assessment = $account?->assessments()->where('state', 'active')->latest('version')->first();

        return $assessment?->obligations()->orderBy('id')->get()
            ->mapWithKeys(fn ($obligation): array => [
                $obligation->id => $obligation->code.' — '.$obligation->label.' (PHP '.$obligation->amount.')',
            ])->all() ?? [];
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
    private function refundPayMongoEventOptions(): array
    {
        return $this->payMongoEventOptions(
            status: OperationalEvent::StatusReviewRequired,
            channel: OperationalEvent::ChannelWebhook,
            includeReasons: ['refund_or_reversal'],
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

    /** @return array<int, string> */
    private function activeCoverageOptions(): array
    {
        return $this->getRecord()->termAccount?->coverages()
            ->where('state', ApprovedCoverage::StateApplied)
            ->with('obligation')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (ApprovedCoverage $coverage): array => [
                $coverage->id => $coverage->obligation->label.' — PHP '.$coverage->amount.' — '.$coverage->authority_reference,
            ])->all() ?? [];
    }

    /** @return array<int, string> */
    private function reversiblePaymentOptions(): array
    {
        $account = $this->getRecord()->termAccount;
        if ($account === null) {
            return [];
        }

        return $account->payments()
            ->where('state', Payment::StatePosted)
            ->whereNotIn('id', Payment::query()->whereNotNull('reverses_payment_id')->select('reverses_payment_id'))
            ->orderByDesc('paid_at')
            ->get()
            ->mapWithKeys(fn (Payment $payment): array => [
                $payment->id => ($payment->provider_reference ?? 'Payment '.$payment->id).' — PHP '.$payment->amount,
            ])->all();
    }

    private function latestSubmittedEvidence(): ?PaymentEvidenceVersion
    {
        return $this->getRecord()->termAccount?->paymentEvidenceVersions()
            ->where('state', PaymentEvidenceVersion::StateSubmitted)
            ->latest('submitted_at')
            ->first();
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
