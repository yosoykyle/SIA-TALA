<?php

namespace App\Filament\Resources\AdmissionApplications\Pages;

use App\Actions\Admissions\AdmissionNotificationLedger;
use App\Actions\Admissions\ChangeAdmissionApplicationLifecycle;
use App\Actions\Admissions\RecordAdmissionDecision;
use App\Actions\Admissions\RecordOfficialCredentialResult;
use App\Actions\Admissions\RequestAdmissionCorrection;
use App\Actions\Admissions\ResolveAdmissionIdentity;
use App\Actions\Admissions\ReviewPreliminaryEvidence;
use App\Filament\Resources\AdmissionApplications\AdmissionApplicationResource;
use App\Filament\Resources\AdmissionCycles\AdmissionCycleResource;
use App\Models\AdmissionApplication;
use App\Models\AdmissionDecision;
use App\Models\ApplicationCorrectionItem;
use App\Models\DocumentEvidence;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
use App\Models\OperationalEvent;
use App\Models\PreliminaryEvidenceReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;

class ViewAdmissionApplication extends ViewRecord
{
    protected static string $resource = AdmissionApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestCorrection')
                ->label('Request scoped correction')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->modalDescription(fn (): string => 'Current: '.$this->application()->application_reference.' is Submitted. Proposed: reopen only the named items for the Applicant. Immediate consequence: state becomes Action Needed; readiness remains unavailable. The submitted version and complete history remain retained.')
                ->schema([
                    Repeater::make('scopes')
                        ->label('Only these fields or evidence items reopen')
                        ->schema([
                            Select::make('scope_type')
                                ->options([
                                    ApplicationCorrectionItem::ScopeField => 'Application field',
                                    ApplicationCorrectionItem::ScopeEvidence => 'Preliminary evidence',
                                ])
                                ->required()
                                ->live(),
                            Select::make('scope_key')
                                ->label('Field')
                                ->options($this->correctableFieldOptions())
                                ->required(fn (Get $get): bool => $get('scope_type') === ApplicationCorrectionItem::ScopeField)
                                ->visible(fn (Get $get): bool => $get('scope_type') === ApplicationCorrectionItem::ScopeField),
                            Select::make('admission_requirement_id')
                                ->label('Evidence requirement')
                                ->options($this->requirementOptions())
                                ->required(fn (Get $get): bool => $get('scope_type') === ApplicationCorrectionItem::ScopeEvidence)
                                ->visible(fn (Get $get): bool => $get('scope_type') === ApplicationCorrectionItem::ScopeEvidence),
                        ])
                        ->minItems(1)
                        ->columns(1)
                        ->required(),
                    Textarea::make('applicant_instruction')->required()->maxLength(1500),
                    TextInput::make('responsible_party')->default('Applicant')->required()->maxLength(120),
                    DateTimePicker::make('due_at')
                        ->label('Correction due')
                        ->native(false)
                        ->minDate(now())
                        ->maxDate(fn (): mixed => $this->application()->admissionCycle?->correction_closes_at)
                        ->required(),
                ])
                ->visible(fn (): bool => $this->canReview()
                    && $this->application()->application_state === AdmissionApplication::StateSubmitted
                    && $this->correctionIssuanceIsOpen())
                ->action(fn (array $data): mixed => $this->runAction(function (User $actor) use ($data): void {
                    $scopes = collect((array) $data['scopes'])
                        ->map(function (array $scope): array {
                            $isEvidence = ($scope['scope_type'] ?? null) === ApplicationCorrectionItem::ScopeEvidence;

                            return [
                                'type' => (string) $scope['scope_type'],
                                'key' => $isEvidence
                                    ? 'requirement:'.(int) $scope['admission_requirement_id']
                                    : (string) $scope['scope_key'],
                                'admission_requirement_id' => $isEvidence
                                    ? (int) $scope['admission_requirement_id']
                                    : null,
                            ];
                        })->all();

                    app(RequestAdmissionCorrection::class)->execute(
                        $this->application(),
                        $actor,
                        $scopes,
                        (string) $data['applicant_instruction'],
                        (string) $data['responsible_party'],
                        CarbonImmutable::parse((string) $data['due_at']),
                    );
                }, 'Scoped correction requested')),
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
                    ->body('Ask the Registrar owner with admission-setup authority to extend the correction boundary. Existing review, decision, and credential work remains available.')
                    ->warning()
                    ->send())
                ->visible(fn (): bool => $this->canReview()
                    && ! $this->canManageAdmissionSetup()
                    && $this->application()->application_state === AdmissionApplication::StateSubmitted
                    && ! $this->correctionIssuanceIsOpen()),
            ActionGroup::make([
                Action::make('reviewEvidence')
                    ->label('Review preliminary evidence')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->schema([
                        Select::make('document_evidence_id')
                            ->label('Evidence version')
                            ->options($this->evidenceOptions())
                            ->required(),
                        Select::make('result')
                            ->options([
                                PreliminaryEvidenceReview::ResultUnderReview => 'Under review',
                                PreliminaryEvidenceReview::ResultAccepted => 'Accepted as preliminary evidence',
                                PreliminaryEvidenceReview::ResultActionNeeded => 'Action needed',
                            ])
                            ->required(),
                        Textarea::make('reason')->maxLength(1000),
                    ])
                    ->visible(fn (): bool => $this->canReview() && $this->evidenceOptions() !== [])
                    ->action(fn (array $data): mixed => $this->runAction(function (User $actor) use ($data): void {
                        $evidence = $this->application()->evidenceVersions->findOrFail((int) $data['document_evidence_id']);
                        $currentReviewId = $evidence->preliminaryReviews->sortByDesc('reviewed_at')->first()?->id;
                        app(ReviewPreliminaryEvidence::class)->execute(
                            $evidence,
                            $actor,
                            (string) $data['result'],
                            filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
                            $currentReviewId,
                        );
                    }, 'Preliminary evidence review recorded')),
                Action::make('resolveIdentity')
                    ->label('Resolve identity warning')
                    ->icon('heroicon-o-identification')
                    ->modalDescription(fn (): string => 'Current: '.$this->application()->application_reference.' has a private identity warning. Proposed: append the selected attributable resolution. Immediate consequence: decision eligibility is recalculated; no Student identity or prior warning is deleted.')
                    ->schema([
                        Select::make('identity_match_review_id')
                            ->label('Private warning')
                            ->options($this->identityWarningOptions())
                            ->required(),
                        Select::make('outcome')
                            ->options([
                                IdentityMatchReview::OutcomeSamePerson => 'Same person',
                                IdentityMatchReview::OutcomeDifferentPerson => 'Different person',
                                IdentityMatchReview::OutcomeCorrectedIdentifier => 'Corrected identifier',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('evidence_reference')->required()->maxLength(255),
                        TextInput::make('corrected_identifier')
                            ->maxLength(64)
                            ->required(fn (Get $get): bool => $get('outcome') === IdentityMatchReview::OutcomeCorrectedIdentifier)
                            ->visible(fn (Get $get): bool => $get('outcome') === IdentityMatchReview::OutcomeCorrectedIdentifier),
                    ])
                    ->visible(fn (): bool => $this->canResolveIdentity() && $this->identityWarningOptions() !== [])
                    ->action(fn (array $data): mixed => $this->runAction(function (User $actor) use ($data): void {
                        $review = $this->application()->identityMatchReviews->findOrFail((int) $data['identity_match_review_id']);
                        app(ResolveAdmissionIdentity::class)->execute(
                            $review,
                            $actor,
                            (string) $data['outcome'],
                            (string) $data['evidence_reference'],
                            filled($data['corrected_identifier'] ?? null) ? (string) $data['corrected_identifier'] : null,
                        );
                    }, 'Identity warning resolved')),
                Action::make('recordDecision')
                    ->label('Record or supersede decision')
                    ->icon('heroicon-o-scale')
                    ->modalDescription(fn (): string => 'Current: '.$this->application()->application_reference.' is '.str($this->application()->application_state)->headline().'. Proposed: append the selected admission decision. Immediate consequence: current outcome and readiness are recalculated; every prior decision remains labelled in history.')
                    ->schema([
                        Select::make('decision')
                            ->options([
                                AdmissionDecision::DecisionAdmitted => 'Admitted',
                                AdmissionDecision::DecisionNotAdmitted => 'Not admitted',
                            ])
                            ->required(),
                        Textarea::make('reason')->label('Internal reason')->required()->maxLength(1500),
                        TextInput::make('authority_reference')->required()->maxLength(255),
                        Textarea::make('applicant_explanation')->required()->maxLength(1500),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && ! in_array($this->application()->application_state, [
                            AdmissionApplication::StateDraft,
                            AdmissionApplication::StateWithdrawn,
                        ], true))
                    ->action(fn (array $data): mixed => $this->runAction(function (User $actor) use ($data): void {
                        app(RecordAdmissionDecision::class)->execute(
                            $this->application(),
                            $actor,
                            (string) $data['decision'],
                            (string) $data['reason'],
                            (string) $data['authority_reference'],
                            (string) $data['applicant_explanation'],
                            $this->currentDecisionId(),
                        );
                    }, 'Admission decision recorded')),
                Action::make('recordCredential')
                    ->label('Record official credential result')
                    ->icon('heroicon-o-check-badge')
                    ->modalDescription(fn (): string => 'Current: '.$this->application()->application_reference.' is Admitted. Proposed: append one official credential result. Immediate consequence: readiness is recalculated from all current sources; prior results and evidence remain retained.')
                    ->schema([
                        Select::make('admission_requirement_id')
                            ->label('Requirement')
                            ->options($this->requirementOptions())
                            ->required(),
                        Select::make('result')
                            ->options([
                                OfficialCredentialResult::ResultNotYetDue => 'Not yet due',
                                OfficialCredentialResult::ResultNotReceived => 'Not received',
                                OfficialCredentialResult::ResultReceivedUnderReview => 'Received under review',
                                OfficialCredentialResult::ResultVerified => 'Verified',
                                OfficialCredentialResult::ResultActionNeeded => 'Action needed',
                                OfficialCredentialResult::ResultAuthorizedException => 'Authorized exception',
                            ])
                            ->required()
                            ->live(),
                        TextInput::make('source_reference')->maxLength(255),
                        Textarea::make('safe_explanation')->required()->maxLength(1500),
                        TextInput::make('authority_reference')->required()->maxLength(255),
                        DateTimePicker::make('exception_expires_at')
                            ->native(false)
                            ->minDate(now())
                            ->required(fn (Get $get): bool => $get('result') === OfficialCredentialResult::ResultAuthorizedException)
                            ->visible(fn (Get $get): bool => $get('result') === OfficialCredentialResult::ResultAuthorizedException),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && $this->application()->application_state === AdmissionApplication::StateAdmitted)
                    ->action(fn (array $data): mixed => $this->runAction(function (User $actor) use ($data): void {
                        $requirement = $this->application()->currentSubmissionVersion?->requirementSet?->requirements
                            ->findOrFail((int) $data['admission_requirement_id']);
                        $currentResult = $this->application()->credentialResults
                            ->where('admission_requirement_id', $requirement->id)
                            ->sortByDesc('recorded_at')
                            ->first();
                        app(RecordOfficialCredentialResult::class)->execute(
                            $this->application(),
                            $requirement,
                            $actor,
                            (string) $data['result'],
                            filled($data['source_reference'] ?? null) ? (string) $data['source_reference'] : null,
                            (string) $data['safe_explanation'],
                            (string) $data['authority_reference'],
                            filled($data['exception_expires_at'] ?? null) ? CarbonImmutable::parse((string) $data['exception_expires_at']) : null,
                            $currentResult?->id,
                        );
                    }, 'Official credential result recorded')),
                Action::make('withdraw')
                    ->label('Record withdrawal')
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(fn (): string => 'Current: '.$this->application()->application_reference.' is '.str($this->application()->application_state)->headline().'. Proposed: record withdrawal. Immediate consequence: readiness is removed; the same reference, submissions, decisions, credentials, and history remain retained. Registrar authority is required to reopen it.')
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(1000),
                        TextInput::make('authority_reference')->required()->maxLength(255),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && ! in_array($this->application()->application_state, [
                            AdmissionApplication::StateDraft,
                            AdmissionApplication::StateWithdrawn,
                        ], true))
                    ->action(fn (array $data): mixed => $this->runAction(function (User $actor) use ($data): void {
                        app(ChangeAdmissionApplicationLifecycle::class)->withdrawByRegistrar(
                            $this->application(),
                            $actor,
                            (string) $data['reason'],
                            (string) $data['authority_reference'],
                        );
                    }, 'Application withdrawal recorded')),
                Action::make('reopen')
                    ->label('Reopen withdrawn application')
                    ->icon('heroicon-o-arrow-path')
                    ->modalDescription(fn (): string => 'Current: '.$this->application()->application_reference.' is Withdrawn. Proposed: reopen the same submitted Application. Immediate consequence: current decision and credential sources are re-evaluated for readiness; withdrawal and all prior history remain retained.')
                    ->schema([
                        Textarea::make('reason')->required()->maxLength(1000),
                        TextInput::make('authority_reference')->required()->maxLength(255),
                    ])
                    ->visible(fn (): bool => $this->canReview()
                        && $this->application()->application_state === AdmissionApplication::StateWithdrawn)
                    ->action(fn (array $data): mixed => $this->runAction(function (User $actor) use ($data): void {
                        app(ChangeAdmissionApplicationLifecycle::class)->reopen(
                            $this->application(),
                            $actor,
                            (string) $data['reason'],
                            (string) $data['authority_reference'],
                        );
                    }, 'Application reopened')),
                Action::make('acknowledgment')
                    ->label('Open acknowledgment')
                    ->icon('heroicon-o-printer')
                    ->url(fn (): string => route('admissions.application.acknowledgment', [
                        'application' => $this->application(),
                        'version' => $this->application()->currentSubmissionVersion,
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
                    ->action(fn (): mixed => $this->runAction(function (User $actor): void {
                        $event = $this->failedNotification();
                        abort_unless($event instanceof OperationalEvent, 404);
                        app(AdmissionNotificationLedger::class)->resend($event, $actor);
                    }, 'Applicant update queued again')),
            ])->label('More actions')->icon('heroicon-o-ellipsis-horizontal'),
        ];
    }

    private function application(): AdmissionApplication
    {
        $record = $this->getRecord();
        abort_unless($record instanceof AdmissionApplication, 404);

        return $record;
    }

    private function canReview(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('review', $this->application());
    }

    private function canResolveIdentity(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('resolveIdentity', $this->application());
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
    private function runAction(callable $operation, string $successTitle): mixed
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        try {
            $operation($actor);
            Notification::make()->title($successTitle)->success()->send();
            $this->redirect(AdmissionApplicationResource::getUrl('view', ['record' => $this->application()]));
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Registrar action blocked')
                ->body($exception->validator->errors()->first())
                ->danger()
                ->send();
        }

        return null;
    }

    /** @return array<string, string> */
    private function correctableFieldOptions(): array
    {
        return collect([
            'program_id', 'application_path',
            'first_name', 'middle_name', 'last_name', 'extension_name', 'birth_date',
            'citizenship_country_code', 'phone', 'current_city_municipality', 'current_province',
            'guardian_full_name', 'guardian_relationship', 'guardian_mobile', 'prior_school_name',
            'prior_school_country_code', 'credential_basis', 'prior_school_completion_year', 'lrn',
            'prior_college_identifier',
        ])->mapWithKeys(fn (string $field): array => [$field => str($field)->headline()->toString()])->all();
    }

    /** @return array<int, string> */
    private function requirementOptions(): array
    {
        return $this->application()->currentSubmissionVersion?->requirementSet?->requirements
            ->sortBy('display_order')
            ->pluck('label', 'id')
            ->all() ?? [];
    }

    /** @return array<int, string> */
    private function evidenceOptions(): array
    {
        return $this->application()->evidenceVersions
            ->sortByDesc('uploaded_at')
            ->mapWithKeys(fn (DocumentEvidence $evidence): array => [
                $evidence->id => $evidence->admissionRequirement->label.' — '.$evidence->uploaded_at->format('M j, Y g:i A'),
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

    private function currentDecisionId(): ?int
    {
        return $this->application()->decisions
            ->sortByDesc('decided_at')
            ->first()?->id;
    }
}
