<?php

namespace App\Filament\Resources\AdmissionApplications\Pages;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Actions\Admissions\AdmissionReviewGuard;
use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Admissions\RecordAdmissionOutcome;
use App\Actions\Admissions\RecordRegistrarEnrollmentClearance;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\ResolveAdmissionIdentity;
use App\Actions\Admissions\ReviewPreliminaryEvidence;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionApplication;
use App\Models\AdmissionCycle;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\AdmissionRequirementSet;
use App\Models\ApplicationCorrectionItem;
use App\Models\DocumentEvidence;
use App\Models\IdentityMatchReview;
use App\Models\OperationalEvent;
use App\Models\PreliminaryEvidenceReview;
use App\Models\RegistrarEnrollmentClearance;
use App\Models\User;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Checkbox;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;

class ViewAdmissionApplication extends ViewRecord
{
    protected static string $resource = AdmissionApplicationResource::class;

    #[Url]
    public ?string $queue = null;

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
        $queue = $this->queue ?? request()->query('queue');
        $breadcrumbs = parent::getBreadcrumbs();

        if (in_array($queue, ['needs_review', 'waiting_for_applicant', 'official_credentials', 'ready_for_enrollment', 'history'], true)) {
            $original = AdmissionApplicationResource::getUrl();
            $contextual = ListAdmissionApplications::getUrl(['tab' => $queue]);
            $breadcrumbs = collect($breadcrumbs)->mapWithKeys(fn (string $label, int|string $url): array => [
                $url === $original ? $contextual : $url => $url === $original ? 'Application queue' : $label,
            ])->all();
        }

        return $breadcrumbs;
    }

    protected function getHeaderActions(): array
    {
        $actions = [
            Action::make('startReview')
                ->label('Start review')->icon('heroicon-o-play')->color('primary')
                ->modalSubmitActionLabel('Start review')
                ->requiresConfirmation()
                ->modalDescription('Start review of this submitted version. Ordinary Applicant edits will lock; later changes need a named correction. Opening this record or a private copy alone does not start review.')
                ->schema([Hidden::make('expected_submission_version_id')->default(fn (): ?int => $this->application()->current_submission_version_id)])
                ->visible(fn (): bool => (bool) auth()->user()?->can('review', $this->application()) && $this->application()->allowsSubmittedEdit())
                ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                    app(AdmissionReviewGuard::class)->start($this->application(), $actor, (int) $data['expected_submission_version_id']);
                }, 'Review started', $action)),
            Action::make('requestCorrection')
                ->label('Request corrections')
                ->modalSubmitActionLabel('Request corrections')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->modalWidth('4xl')
                ->modalDescription(fn (): string => 'Reopen only the named fields and copies in '.$this->application()->application_reference.'. The Applicant receives your instruction and due date. Readiness remains unavailable until the required corrections and review are complete; submitted history is retained.')
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
                        ->label('Applicant instruction')
                        ->helperText('Specific instructions for the Applicant explaining what needs correction.')
                        ->required()
                        ->maxLength(1500),
                    DateTimePicker::make('due_at')
                        ->label('Correction due (Manila time)')
                        ->helperText('Choose a date and time on or before the cycle correction boundary. An active correction remains available after its deadline.')
                        ->timezone(config('app.display_timezone'))
                        ->displayFormat('M j, Y · g:i A')
                        ->seconds(false)
                        ->prefixIcon('heroicon-o-calendar-days')
                        ->native(false)
                        ->minDate(now())
                        ->maxDate(fn (): mixed => $this->application()->admissionCycle?->correction_closes_at)
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
                }, 'Scoped correction requested', $action)),
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
                    ->modalDescription('The current decision, identity review, and source-matching Registrar clearance satisfy admissions readiness. The Applicant follows the published enrollment availability. Official enrollment is a separate journey.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->visible(fn (): bool => $this->application()->application_state === AdmissionApplication::StateAdmitted
                        && app(ReadyApplicantProjectionQuery::class)->forApplication($this->application())['ready']),
                Action::make('reviewEvidence')
                    ->label('Review preliminary evidence')
                    ->modalSubmitActionLabel('Save review')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->fillForm(function (array $arguments): array {
                        $evidenceId = $arguments['document_evidence_id'] ?? null;
                        $evidence = $evidenceId ? $this->application()->evidenceVersions->find((int) $evidenceId) : null;

                        return [
                            'document_evidence_id' => $evidenceId ? (int) $evidenceId : null,
                            'expected_current_review_id' => $evidence?->preliminaryReviews()->whereDoesntHave('successor')->value('id'),
                            'expected_submission_version_id' => $this->application()->current_submission_version_id,
                        ];
                    })
                    ->schema([
                        Radio::make('document_evidence_id')
                            ->label('Choose the submitted copy to review')
                            ->helperText('Select a copy, then use View selected copy to inspect it before recording the result. Earlier versions remain in history.')
                            ->options($this->evidenceOptions())
                            ->live()
                            ->afterStateHydrated(function (Set $set, mixed $state): void {
                                if (filled($state)) {
                                    $evidence = $this->application()->evidenceVersions->find((int) $state);
                                    $set('expected_current_review_id', $evidence?->preliminaryReviews()->whereDoesntHave('successor')->value('id'));
                                }
                            })
                            ->afterStateUpdated(function (Set $set, mixed $state): void {
                                $evidence = $this->application()->evidenceVersions->find((int) $state);
                                $set('expected_current_review_id', $evidence?->preliminaryReviews()->whereDoesntHave('successor')->value('id'));
                            })
                            ->required(),
                        Hidden::make('expected_current_review_id'),
                        Hidden::make('expected_submission_version_id')->default(fn () => $this->application()->current_submission_version_id),
                        Actions::make([
                            Action::make('viewSelectedEvidence')
                                ->label('View selected copy')->icon('heroicon-o-eye')->color('gray')
                                ->url(fn (Get $get): ?string => $this->application()->evidenceVersions->contains('id', (int) $get('document_evidence_id'))
                                    ? route('admissions.evidence.view', ['evidence' => (int) $get('document_evidence_id')]) : null)
                                ->openUrlInNewTab()
                                ->visible(fn (Get $get): bool => filled($get('document_evidence_id'))),
                            Action::make('downloadSelectedEvidence')
                                ->label('Download selected private evidence')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('gray')
                                ->url(fn (Get $get): ?string => $this->application()->evidenceVersions->contains('id', (int) $get('document_evidence_id'))
                                    ? route('admissions.evidence.download', ['evidence' => (int) $get('document_evidence_id')]) : null)
                                ->openUrlInNewTab()
                                ->visible(fn (Get $get): bool => filled($get('document_evidence_id'))),
                        ]),
                        Select::make('result')
                            ->options([
                                PreliminaryEvidenceReview::ResultUnderReview => 'Under review',
                                PreliminaryEvidenceReview::ResultAccepted => 'Accepted as preliminary evidence',
                                PreliminaryEvidenceReview::ResultActionNeeded => 'Action needed',
                            ])
                            ->live()
                            ->required(),
                        Textarea::make('reason')
                            ->label('Review note')
                            ->helperText(fn (Get $get): string => $get('result') === PreliminaryEvidenceReview::ResultActionNeeded
                                ? 'Required: explain the problem and what the Applicant needs to replace or clarify.'
                                : 'Add a brief basis for this copy’s review result when useful.')
                            ->placeholder('For example: The birth date is unreadable. Upload a clear copy showing the full page.')
                            ->required(fn (Get $get): bool => $get('result') === PreliminaryEvidenceReview::ResultActionNeeded)
                            ->rows(3)
                            ->maxLength(1000),
                    ])
                    ->visible(fn (): bool => $this->canReview() && $this->evidenceOptions() !== [])
                    ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                        $evidence = $this->application()->evidenceVersions->findOrFail((int) $data['document_evidence_id']);
                        $expectedReviewId = filled($data['expected_current_review_id'] ?? null)
                            ? (int) $data['expected_current_review_id']
                            : null;

                        app(ReviewPreliminaryEvidence::class)->execute(
                            $evidence,
                            $actor,
                            (string) $data['result'],
                            filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                            $expectedReviewId,
                            (int) $data['expected_submission_version_id'],
                        );
                    }, 'Preliminary evidence review recorded', $action)),
                Action::make('resolveIdentity')
                    ->label('Resolve identity warning')
                    ->modalSubmitActionLabel('Record identity resolution')
                    ->icon('heroicon-o-identification')
                    ->modalDescription(fn (): string => 'Record the checked identity result for '.$this->application()->application_reference.'. Decision eligibility is recalculated. Existing identities and warning history are retained.')
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
                Action::make('recordDecision')
                    ->label('Record admission decision')
                    ->modalSubmitActionLabel('Record decision')
                    ->icon('heroicon-o-scale')
                    ->modalDescription(function (): string {
                        $isReplacement = filled($this->application()->decisions()->whereDoesntHave('successor')->value('id'));
                        $currentOutcome = str($this->application()->application_state)->headline();

                        return $isReplacement
                            ? "Replace the current {$currentOutcome} decision for {$this->application()->application_reference}. An approval reference is required. Previous decisions remain in history; readiness is recalculated."
                            : "Record the first decision for {$this->application()->application_reference} under your Registrar authority. The Applicant receives your explanation. The decision is retained and readiness is recalculated.";
                    })
                    ->schema([
                        Hidden::make('expected_current_decision_id')->default(fn (): ?int => $this->application()->decisions()->whereDoesntHave('successor')->value('id')),
                        Hidden::make('expected_submission_version_id')->default(fn (): ?int => $this->application()->current_submission_version_id),
                        Radio::make('decision')
                            ->live()
                            ->options([
                                AdmissionDecision::DecisionAdmitted => 'Admitted',
                                AdmissionDecision::DecisionNotAdmitted => 'Not admitted',
                            ])
                            ->disableOptionWhen(fn (string $value): bool => $value === AdmissionDecision::DecisionAdmitted && (! $this->preliminaryReviewIsComplete() || $this->identityWarningOptions() !== []))
                            ->helperText(fn (): ?string => $this->admissionBlockerGuidance())
                            ->required(),
                        Radio::make('papers_confirmed')
                            ->label('External school checks')
                            ->options([0 => 'Admit — papers pending', 1 => 'Admit — papers confirmed'])
                            ->default(0)->live()
                            ->visible(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionAdmitted),
                        Checkbox::make('external_checks_confirmed')
                            ->label('I confirm the school completed all required external checks.')
                            ->visible(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionAdmitted && (bool) $get('papers_confirmed'))
                            ->accepted(fn (Get $get): bool => $get('decision') === AdmissionDecision::DecisionAdmitted && (bool) $get('papers_confirmed')),
                        Select::make('decision_reason')
                            ->label('Common review reason')->options([
                                'Required evidence is acceptable' => 'Required evidence is acceptable',
                                'Required evidence remains incomplete' => 'Required evidence remains incomplete',
                                'Unsupported admission eligibility' => 'Unsupported admission eligibility', 'Other' => 'Other',
                            ])->live()->afterStateUpdated(function (Set $set, ?string $state): void {
                                if (filled($state) && $state !== 'Other') {
                                    $set('reason', $state);
                                }
                            }),
                        Textarea::make('reason')->label('Private review reason')
                            ->helperText('Record the basis for the decision. This note is for authorized staff.')
                            ->placeholder('Summarize the reviewed evidence and the reason for this outcome.')
                            ->rows(3)->required()->maxLength(1500),
                        Textarea::make('applicant_explanation')->label('Applicant explanation')
                            ->helperText('Shown to the Applicant. Explain the outcome and a safe next step without private identity or staff details.')
                            ->placeholder('Explain what happens next or how to contact Admissions for help.')
                            ->rows(3)->required()->maxLength(1500),
                        Checkbox::make('requires_external_approval')
                            ->label('This decision records an exceptional approval')
                            ->helperText('Select only when a verified school policy requires approval beyond the routine Registrar decision. Admission safeguards still apply.')
                            ->default(false)
                            ->live(),
                        TextInput::make('authority_reference')
                            ->label('Separate approval reference')
                            ->helperText('Required for a replacement decision or exceptional approval. Enter the school approval or recorded authorization reference; an ordinary first decision uses your recorded Registrar authority.')
                            ->required(fn (Get $get): bool => filled($get('expected_current_decision_id')) || (bool) $get('requires_external_approval'))
                            ->visible(fn (Get $get): bool => filled($get('expected_current_decision_id')) || (bool) $get('requires_external_approval'))
                            ->maxLength(255),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && in_array($this->application()->application_state, [
                            AdmissionApplication::StateSubmitted,
                            AdmissionApplication::StateAdmitted,
                            AdmissionApplication::StateNotAdmitted,
                        ], true))
                    ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                        if (($data['decision'] ?? null) === AdmissionDecision::DecisionAdmitted) {
                            if (! $this->preliminaryReviewIsComplete()) {
                                throw ValidationException::withMessages([
                                    'decision' => 'Cannot record Admitted while preliminary evidence reviews are incomplete.',
                                ]);
                            }
                            if ($this->identityWarningOptions() !== []) {
                                throw ValidationException::withMessages([
                                    'decision' => 'Cannot record Admitted while identity warnings remain unresolved.',
                                ]);
                            }
                        }

                        $expectedDecisionId = filled($data['expected_current_decision_id'] ?? null)
                            ? (int) $data['expected_current_decision_id']
                            : null;
                        $expectedSubmissionVersionId = filled($data['expected_submission_version_id'] ?? null)
                            ? (int) $data['expected_submission_version_id']
                            : null;

                        app(RecordAdmissionOutcome::class)->execute(
                            $this->application(),
                            $actor,
                            (string) $data['decision'],
                            (string) $data['reason'],
                            filled($data['authority_reference'] ?? null) ? (string) $data['authority_reference'] : null,
                            (string) $data['applicant_explanation'],
                            $expectedDecisionId,
                            $expectedSubmissionVersionId,
                            requiresExternalApproval: (bool) ($data['requires_external_approval'] ?? false),
                            papersConfirmed: (bool) ($data['papers_confirmed'] ?? false) && (bool) ($data['external_checks_confirmed'] ?? false),
                        );
                    }, 'Admission decision recorded', $action)),
                Action::make('recordEnrollmentClearance')
                    ->label(fn (): string => $this->application()->enrollmentClearances()->whereDoesntHave('successor')->exists() ? 'Update enrollment clearance' : 'Mark papers complete')
                    ->modalSubmitActionLabel('Record clearance')
                    ->icon('heroicon-o-check-badge')
                    ->modalDescription(function (): string {
                        $currentClearance = $this->application()->enrollmentClearances()->whereDoesntHave('successor')->first();
                        $status = $currentClearance ? "Current clearance: {$currentClearance->result}" : 'No clearance recorded yet';

                        return "{$status}. Record the Registrar outcome for external physical credential and enrollment verification. Cleared enables admission enrollment readiness; Action needed notifies the Applicant. Historical clearances remain immutable.";
                    })
                    ->schema([
                        Hidden::make('expected_current_clearance_id')
                            ->default(fn () => $this->application()->enrollmentClearances()->whereDoesntHave('successor')->value('id')),
                        Hidden::make('expected_decision_id')
                            ->default(fn () => $this->application()->decisions()->whereDoesntHave('successor')->value('id')),
                        Hidden::make('expected_submission_version_id')
                            ->default(fn () => $this->application()->current_submission_version_id),
                        Select::make('result')
                            ->options([
                                RegistrarEnrollmentClearance::ResultCleared => 'Cleared',
                                RegistrarEnrollmentClearance::ResultActionNeeded => 'Action needed',
                            ])
                            ->required()
                            ->live(),
                        Checkbox::make('external_checks_confirmed')
                            ->label('I confirm the required external enrollment checks are complete.')
                            ->visible(fn (Get $get): bool => $get('result') === RegistrarEnrollmentClearance::ResultCleared)
                            ->accepted(fn (Get $get): bool => $get('result') === RegistrarEnrollmentClearance::ResultCleared),
                        Textarea::make('safe_instruction')
                            ->label('Instruction for the Applicant')
                            ->visible(fn (Get $get): bool => $get('result') === RegistrarEnrollmentClearance::ResultActionNeeded)
                            ->required(fn (Get $get): bool => $get('result') === RegistrarEnrollmentClearance::ResultActionNeeded)
                            ->maxLength(1500),
                        Textarea::make('reason')
                            ->label('Reason for replacing the previous clearance')
                            ->visible(fn (Get $get): bool => filled($get('expected_current_clearance_id')))
                            ->required(fn (Get $get): bool => filled($get('expected_current_clearance_id')))
                            ->maxLength(1500),
                        TextInput::make('authority_reference')
                            ->label('Supporting approval or correction reference (if applicable)')
                            ->helperText('Identify an existing school approval when this clearance records an exception or correction. Routine clearance already records your Registrar identity, time and submitted source.')
                            ->placeholder('For example: approval memo or recorded correction reference')
                            ->maxLength(255),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && $this->application()->application_state === AdmissionApplication::StateAdmitted)
                    ->action(fn (array $data, Action $action): mixed => $this->runAction(function (User $actor) use ($data): void {
                        $expectedClearanceId = filled($data['expected_current_clearance_id'] ?? null)
                            ? (int) $data['expected_current_clearance_id']
                            : null;
                        $expectedDecisionId = filled($data['expected_decision_id'] ?? null)
                            ? (int) $data['expected_decision_id']
                            : null;
                        $expectedSubmissionVersionId = filled($data['expected_submission_version_id'] ?? null)
                            ? (int) $data['expected_submission_version_id']
                            : null;

                        app(RecordRegistrarEnrollmentClearance::class)->execute(
                            $this->application(),
                            $actor,
                            (string) $data['result'],
                            (bool) ($data['external_checks_confirmed'] ?? false),
                            $data['safe_instruction'] ?? null,
                            $data['reason'] ?? null,
                            $data['authority_reference'] ?? null,
                            $expectedClearanceId,
                            $expectedDecisionId,
                            $expectedSubmissionVersionId,
                        );
                    }, 'Enrollment clearance recorded', $action)),
                Action::make('withdraw')
                    ->label('Record withdrawal')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalWidth(Width::Large)
                    ->modalDescription('This application will be withdrawn and will no longer be ready for enrollment. Its records stay in history. Reopening requires Registrar approval.')
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
                    ->modalDescription(fn (): string => 'Reopen '.$this->application()->application_reference.' with the recorded approval. Current sources are checked again for readiness. Withdrawal and prior history are retained.')
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
        $primaryName = match ($this->application()->application_state) {
            AdmissionApplication::StateSubmitted => ! $this->application()->reviewHasStarted() ? 'startReview' : ($this->identityWarningOptions() !== []
                ? 'resolveIdentity'
                : ($this->preliminaryReviewIsComplete() ? 'recordDecision' : 'reviewEvidence')),
            AdmissionApplication::StateAdmitted => app(ReadyApplicantProjectionQuery::class)->forApplication($this->application())['ready']
                ? 'reviewReadiness'
                : 'recordEnrollmentClearance',
            AdmissionApplication::StateWithdrawn,
            AdmissionApplication::StateActionNeeded,
            AdmissionApplication::StateNotAdmitted => null,
            default => 'acknowledgment',
        };
        $secondary = [];
        $primary = null;

        foreach ([...$actions, ...$group->getActions()] as $action) {
            if ($action instanceof Action && $action->getName() === $primaryName) {
                $primary = $action->group(null)->button()->color($primaryName === 'reviewReadiness' ? 'gray' : 'primary');
            } else {
                $secondary[] = $action;
            }
        }

        return array_values(array_filter([$primary, $group->actions($secondary)]));
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

        return $actor instanceof User && $actor->can('review', $this->application()) && $this->application()->reviewHasStarted();
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

    private function preliminaryReviewIsComplete(): bool
    {
        return app(ReadyApplicantProjectionQuery::class)->preliminaryReviewIsComplete($this->application());
    }

    /** @return array<int, string> */
    private function evidenceOptions(): array
    {
        $requirements = $this->application()->currentSubmissionVersion?->requirementSet?->requirements->pluck('id') ?? collect();

        return $this->application()->evidenceVersions->whereIn('admission_requirement_id', $requirements)
            ->sortByDesc('id')->unique('admission_requirement_id')
            ->mapWithKeys(fn (DocumentEvidence $evidence): array => [
                $evidence->id => $evidence->admissionRequirement->label.' — '.$evidence->uploaded_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A').' — Current',
            ])->all();
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
