<?php

namespace App\Filament\Resources\AdmissionApplications\Schemas;

use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionCycle;
use App\Models\AdmissionRequirement;
use App\Models\ApplicationCorrectionRequest;
use App\Models\DocumentEvidence;
use App\Models\PreliminaryEvidenceReview;
use App\Models\RegistrarEnrollmentClearance;
use App\Queries\Admissions\ReadyApplicantProjectionQuery;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Collection;

class AdmissionApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $record = $schema->getRecord();

        if ($record instanceof AdmissionApplication) {
            $record->loadMissing([
                'submissionVersions.submitter',
                'submissionVersions.application',
                'decisions.decisionMaker',
                'decisions.successor',
                'decisions.supersededDecision',
                'decisions.application.submissionVersions',
                'enrollmentClearances.recorder',
                'enrollmentClearances.successor',
                'enrollmentClearances.application.submissionVersions',
                'correctionRequests.requester',
                'events.actor',
            ]);
        }

        return $schema
            ->columns(['default' => 1, 'xl' => 2])
            ->components([
                Tabs::make('ApplicantRecord')
                    ->extraAttributes(['class' => 'tala-applicant-record-tabs'])
                    ->tabs([
                        Tab::make('Current work')
                            ->icon('heroicon-o-clipboard-document-check')
                            ->schema([
                                Group::make([
                                    TextEntry::make('situation')
                                        ->label('Current task')
                                        ->weight('semibold')
                                        ->helperText(fn (AdmissionApplication $record): string => ($record->program?->name ?? 'Program unavailable').' · '.AdmissionCycle::studentTypeLabel($record->application_path))
                                        ->state(fn (AdmissionApplication $record): string => match ($record->application_state) {
                                            AdmissionApplication::StateDraft => 'Draft preparation — Applicant submission remains required',
                                            AdmissionApplication::StateSubmitted => $record->reviewHasStarted() ? 'Registrar review is in progress' : 'Application received — awaiting Registrar review',
                                            AdmissionApplication::StateActionNeeded => 'Applicant corrections are pending',
                                            AdmissionApplication::StateAdmitted => app(ReadyApplicantProjectionQuery::class)->forApplication($record)['ready']
                                                ? 'Admissions complete — ready for the enrollment journey'
                                                : 'Admitted — complete Registrar clearance',
                                            AdmissionApplication::StateNotAdmitted => 'Review complete — Not admitted',
                                            AdmissionApplication::StateWithdrawn => 'Application withdrawn — retained for reference',
                                            default => 'Review the current application',
                                        })
                                        ->columnSpanFull(),
                                    Group::make([
                                        TextEntry::make('responsible_party')
                                            ->label('Responsible party')
                                            ->state(fn (AdmissionApplication $record): string => app(ReadyApplicantProjectionQuery::class)->responsibleParty($record))
                                            ->columnSpan(['default' => 1, 'md' => 1]),
                                        TextEntry::make('requested_student_type')->label('Requested Student type')
                                            ->state(fn (AdmissionApplication $record): ?string => $record->correctionRequests()->where('state', ApplicationCorrectionRequest::StateActive)
                                                ->whereNotNull('target_application_path')->value('target_application_path'))
                                            ->formatStateUsing(fn (string $state): string => AdmissionCycle::studentTypeLabel($state))
                                            ->visible(fn (?string $state): bool => filled($state)),
                                        TextEntry::make('next_action')
                                            ->label('Next action')
                                            ->state(fn (AdmissionApplication $record): string => match ($record->application_state) {
                                                AdmissionApplication::StateDraft => app(ReadyApplicantProjectionQuery::class)->draftCycleIsOpen($record)
                                                    ? 'Applicant completes and submits the five-step Application; Registrar assistance does not submit it.'
                                                    : app(ReadyApplicantProjectionQuery::class)->draftNextAction($record),
                                                AdmissionApplication::StateSubmitted => app(ReadyApplicantProjectionQuery::class)->submittedNextAction($record),
                                                AdmissionApplication::StateActionNeeded => 'Wait for the Applicant to submit the requested corrections.',
                                                AdmissionApplication::StateAdmitted => app(ReadyApplicantProjectionQuery::class)->forApplication($record)['ready']
                                                    ? app(ReadyApplicantProjectionQuery::class)->enrollmentNextAction($record)
                                                    : collect(app(ReadyApplicantProjectionQuery::class)->forApplication($record)['blockers'])
                                                        ->map(fn (array $finding): string => $finding['source'].': '.$finding['recovery'])->implode(' '),
                                                AdmissionApplication::StateNotAdmitted => 'Keep the recorded decision and explanation available in history.',
                                                AdmissionApplication::StateWithdrawn => 'Retain history or reopen only with authority before registration begins.',
                                                default => 'Review the application record.',
                                            })
                                            ->columnSpan(['default' => 1, 'md' => 1]),
                                        TextEntry::make('applicant_instruction')
                                            ->label('Current Applicant instruction')
                                            ->visible(fn (TextEntry $component): bool => filled($component->getState()))
                                            ->state(function (AdmissionApplication $record): ?string {
                                                $correction = $record->correctionRequests
                                                    ->where('state', ApplicationCorrectionRequest::StateActive)->sortByDesc('sequence')->first();
                                                if ($correction !== null) {
                                                    return $correction->applicant_instruction;
                                                }
                                                $projection = app(ReadyApplicantProjectionQuery::class)->forApplication($record);
                                                if ($projection['ready']) {
                                                    return 'Enrollment clearance is complete. Follow the published enrollment availability.';
                                                }
                                                if ($projection['clearance_result'] === RegistrarEnrollmentClearance::ResultActionNeeded) {
                                                    return collect($projection['blockers'])->firstWhere('source', 'Registrar enrollment clearance')['recovery'] ?? null;
                                                }

                                                return $record->decisions->sortByDesc('id')->first()?->applicant_explanation;
                                            })
                                            ->color('gray'),
                                    ])
                                        ->columns(['default' => 1, 'md' => 3])
                                        ->extraAttributes(['class' => 'tala-current-task-facts']),
                                ])
                                    ->extraAttributes(['class' => 'tala-registrar-current-task']),
                                Section::make('Preliminary evidence')
                                    ->contained(false)
                                    ->compact()
                                    ->schema([
                                        RepeatableEntry::make('evidenceVersions')
                                            ->hiddenLabel()
                                            ->state(fn (AdmissionApplication $record) => self::currentEvidence($record))
                                            ->table([
                                                TableColumn::make('Requirement'),
                                                TableColumn::make('Copy / Uploaded'),
                                                TableColumn::make('Current review result'),
                                                TableColumn::make('Actions')->hiddenHeaderLabel(),
                                            ])
                                            ->schema(self::evidenceSchema())
                                            ->extraAttributes(['class' => 'tala-evidence-table'])
                                            ->placeholder('No preliminary review copy submitted.')
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Supporting facts')
                                    ->contained(false)
                                    ->compact()
                                    ->schema([
                                        View::make('filament.admin.admission-applications.supporting-facts')
                                            ->viewData(fn (AdmissionApplication $record): array => self::supportingFacts($record)),
                                    ]),
                                Section::make('Decision basis')
                                    ->schema([
                                        TextEntry::make('decision_private_basis')
                                            ->label('Registrar review basis')
                                            ->visible(fn (AdmissionApplication $record): bool => $record->decisions->whereNull('successor')->isNotEmpty())
                                            ->state(function (AdmissionApplication $record): ?string {
                                                $decision = $record->decisions->whereNull('successor')->sortByDesc('decided_at')->first();

                                                return $decision?->reason;
                                            })
                                            ->helperText(function (AdmissionApplication $record): ?string {
                                                $decision = $record->decisions->whereNull('successor')->sortByDesc('decided_at')->first();
                                                if (filled($decision?->authority_reference)) {
                                                    return 'Authority reference: '.$decision->authority_reference;
                                                }

                                                return null;
                                            }),
                                    ])
                                    ->visible(fn (AdmissionApplication $record): bool => $record->decisions->isNotEmpty())
                                    ->compact()->collapsible()->collapsed(),
                            ]),
                        Tab::make('Process evaluation')
                            ->icon('heroicon-o-list-bullet')
                            ->schema([
                                View::make('filament.admin.admission-applications.process-evaluation')
                                    ->viewData(fn (AdmissionApplication $record, ViewAdmissionApplication $livewire): array => [
                                        'steps' => $livewire->processEvaluation(),
                                        'canNotify' => (bool) auth()->user()?->can('review', $record),
                                    ]),
                            ]),
                        Tab::make('Record history')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                View::make('filament.admin.admission-applications.activity-evidence')
                                    ->viewData(fn (AdmissionApplication $record): array => [
                                        'application' => $record,
                                        'events' => $record->events->sortByDesc('occurred_at')->values(),
                                        'timezone' => config('app.display_timezone'),
                                    ]),
                            ]),
                    ])
                    ->columnSpan(['default' => 'full', 'xl' => 1]),
                Tabs::make('ApplicantDetails')
                    ->extraAttributes(['class' => 'tala-applicant-record-tabs tala-applicant-details-panel'])
                    ->tabs([
                        Tab::make('Applicant details')
                            ->icon('heroicon-o-user')
                            ->schema([
                                Group::make([
                                    TextEntry::make('application_reference')
                                        ->label('Application reference')
                                        ->copyable()
                                        ->extraAttributes(['class' => 'tala-applicant-identity-reference'])
                                        ->columnSpanFull(),
                                    TextEntry::make('email')->label('Verified account email')->copyable(),
                                    TextEntry::make('birth_date')->label('Birth date')->date()->placeholder('Not provided'),
                                ])
                                    ->key('applicant')
                                    ->columns(['default' => 1, 'sm' => 2])
                                    ->extraAttributes(['class' => 'tala-applicant-identity']),
                                Section::make('Personal Information')
                                    ->schema([
                                        TextEntry::make('legal_name')
                                            ->label('Legal name')
                                            ->state(fn (AdmissionApplication $record): string => collect([
                                                $record->first_name,
                                                $record->middle_name,
                                                $record->last_name,
                                                $record->extension_name,
                                            ])->filter()->implode(' ')),
                                        TextEntry::make('phone')->label('Mobile'),
                                        TextEntry::make('current_locality')
                                            ->label('Current locality')
                                            ->state(fn (AdmissionApplication $record): string => collect([
                                                $record->current_city_municipality,
                                                $record->current_province,
                                            ])->filter()->implode(', ')),
                                    ])
                                    ->columns(['default' => 1, 'md' => 2])
                                    ->contained(false)
                                    ->compact(),
                                Section::make('Educational Information')
                                    ->schema([
                                        TextEntry::make('prior_school_name')->label('Previous school attended'),
                                        TextEntry::make('credential_basis')->label('Admission qualification')
                                            ->formatStateUsing(fn (string $state, AdmissionApplication $record): string => AdmissionApplication::admissionQualificationOptions((string) $record->application_path)[$state]
                                                ?? str($state)->lower()->headline()->toString()),
                                        TextEntry::make('prior_school_completion_year')->label('Graduation year')->placeholder('Not provided'),
                                        TextEntry::make('lrn_availability')->label('LRN availability')->placeholder('Not recorded')
                                            ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                                        TextEntry::make('lrn')->label('LRN')->placeholder('No identifier provided'),
                                    ])
                                    ->columns(['default' => 1, 'md' => 2])
                                    ->contained(false)
                                    ->compact(),
                                Section::make('Application Choice')
                                    ->schema([
                                        TextEntry::make('admissionCycle.label')->label('Admission cycle'),
                                        TextEntry::make('term.label')->label('Target term'),
                                        TextEntry::make('program.name')->label('Program'),
                                        TextEntry::make('application_path')->label('Student type')->formatStateUsing(fn (string $state): string => AdmissionCycle::studentTypeLabel($state)),
                                    ])
                                    ->columns(['default' => 1, 'md' => 2])
                                    ->contained(false)
                                    ->compact(),
                                Section::make('Emergency Contact')
                                    ->schema([
                                        TextEntry::make('guardian_full_name')->label('Parent, guardian or emergency contact')->placeholder('Not provided'),
                                        TextEntry::make('guardian_relationship')->label('Relationship')->placeholder('Not provided'),
                                        TextEntry::make('guardian_mobile')->label('Contact mobile')->placeholder('Not provided'),
                                    ])
                                    ->columns(['default' => 1, 'md' => 2])
                                    ->contained(false)
                                    ->compact(),
                            ]),
                    ])
                    ->columnSpan(['default' => 'full', 'xl' => 1]),
            ]);
    }

    /**
     * @return array{review: array<string, mixed>, clearance: array<string, mixed>|null}
     */
    private static function supportingFacts(AdmissionApplication $application): array
    {
        $timezone = (string) config('app.display_timezone');
        $format = fn ($moment): ?string => $moment?->timezone($timezone)->format('M j, Y · g:i A');
        $projection = app(ReadyApplicantProjectionQuery::class)->forApplication($application);
        $isAdmitted = $application->application_state === AdmissionApplication::StateAdmitted;

        $start = $application->events->firstWhere('event_type', AdmissionApplicationEvent::TypeReviewStarted);
        $startedVersion = $start !== null ? $application->submissionVersions()->whereKey($start->source_id)->value('version') : null;
        $activeCorrection = $application->correctionRequests->where('state', ApplicationCorrectionRequest::StateActive)->sortByDesc('sequence')->first();

        return [
            'review' => [
                'started_by' => $start !== null ? ($start->actor?->getFilamentName() ?? 'Recorded Registrar') : null,
                'started_at' => $start !== null ? $format($start->occurred_at) : null,
                'started_note' => $start !== null ? null : ($application->reviewHasStarted()
                    ? 'Earlier review evidence is retained. An explicit start time was not recorded; ordinary edits remain locked.'
                    : 'Not started. The Applicant can commit a complete update until you choose Start review.'),
                'reviewing' => $start !== null ? ($startedVersion !== null ? 'Submission #'.$startedVersion : 'A retained submitted source') : null,
                'received' => $format($application->submitted_at) ?? 'Not submitted',
                'closing' => $format($application->admissionCycle?->closes_at) ?? 'Not set',
                'correction_due' => $format($activeCorrection?->due_at),
            ],
            'clearance' => $isAdmitted ? [
                'result' => match ($projection['clearance_result']) {
                    RegistrarEnrollmentClearance::ResultCleared => 'Cleared',
                    RegistrarEnrollmentClearance::ResultActionNeeded => 'Action needed',
                    default => 'Awaiting Registrar clearance',
                },
                'cleared' => $projection['clearance_result'] === RegistrarEnrollmentClearance::ResultCleared,
                'ready' => (bool) $projection['ready'],
                'readiness_note' => $projection['ready']
                    ? 'Admission, identity review and Registrar clearance are complete. Official enrollment is separate.'
                    : 'Complete the remaining admissions checks shown in Next action.',
            ] : null,
        ];
    }

    private static function currentEvidence(AdmissionApplication $application): Collection
    {
        $set = $application->currentSubmissionVersion?->requirementSet;
        if (! $set) {
            return $application->evidenceVersions->sortByDesc('id')->unique('admission_requirement_id')->values();
        }

        $preliminaryRequirements = $set->requirements
            ->filter(fn (AdmissionRequirement $requirement): bool => (bool) $requirement->requires_preliminary_evidence || $requirement->due_stage === AdmissionRequirement::DuePreliminaryReview)
            ->sortBy('display_order');

        $rows = collect();
        $coveredRequirementIds = collect();

        foreach ($preliminaryRequirements as $requirement) {
            $coveredRequirementIds->push($requirement->id);
            $evidence = $application->evidenceVersions
                ->where('admission_requirement_id', $requirement->id)
                ->sortByDesc('id')
                ->first();

            if ($evidence) {
                $rows->push($evidence);
            } else {
                $missing = new DocumentEvidence([
                    'admission_application_id' => $application->id,
                    'admission_requirement_id' => $requirement->id,
                ]);
                $missing->exists = false;
                $missing->id = null;
                $missing->setRelation('admissionApplication', $application);
                $missing->setRelation('admissionRequirement', $requirement);
                $missing->setRelation('preliminaryReviews', collect());
                $rows->push($missing);
            }
        }

        return new Collection($rows->all());
    }

    private static function evidenceSchema(): array
    {
        return [
            TextEntry::make('admissionRequirement.label')->label('Requirement')->weight('semibold'),
            TextEntry::make('version_status')->label('Copy / Uploaded')
                ->state(function (DocumentEvidence $record): string {
                    if (! $record->exists || $record->id === null) {
                        return 'Missing copy — not uploaded';
                    }

                    $application = $record->admissionApplication;
                    if ($application && $application->relationLoaded('evidenceVersions')) {
                        $isCurrent = $application->evidenceVersions
                            ->where('admission_requirement_id', $record->admission_requirement_id)
                            ->max('id') === $record->id;

                        return $isCurrent ? 'Current evidence' : 'Superseded — retained history';
                    }

                    return DocumentEvidence::query()
                        ->where('admission_application_id', $record->admission_application_id)
                        ->where('admission_requirement_id', $record->admission_requirement_id)
                        ->where('id', '>', $record->id)->exists() ? 'Superseded — retained history' : 'Current evidence';
                })
                ->badge(fn (DocumentEvidence $record): bool => ! $record->exists || $record->id === null)
                ->color(fn (DocumentEvidence $record): ?string => (! $record->exists || $record->id === null) ? 'danger' : null)
                ->helperText(fn (DocumentEvidence $record): ?string => $record->uploaded_at?->timezone(config('app.display_timezone'))->format('M j, Y · g:i A')),
            TextEntry::make('review_history')->label('Current review result')
                ->state(function (DocumentEvidence $record): string {
                    if (! $record->exists || $record->id === null) {
                        return 'Missing preliminary copy';
                    }

                    $currentReview = $record->admissionApplication->currentPreliminaryReview($record);

                    if ($currentReview === null) {
                        return 'Awaiting preliminary review';
                    }

                    return $currentReview->result === PreliminaryEvidenceReview::ResultAccepted
                        ? 'Review copy accepted'
                        : str($currentReview->result)->headline()->toString();
                })
                ->badge()
                ->color(function (DocumentEvidence $record): string {
                    if (! $record->exists || $record->id === null) {
                        return 'warning';
                    }

                    $currentReview = $record->admissionApplication->currentPreliminaryReview($record);

                    return match ($currentReview?->result) {
                        PreliminaryEvidenceReview::ResultAccepted => 'success',
                        PreliminaryEvidenceReview::ResultActionNeeded => 'warning',
                        PreliminaryEvidenceReview::ResultUnderReview => 'warning',
                        default => 'gray',
                    };
                })
                ->helperText(function (DocumentEvidence $record): ?string {
                    if (! $record->exists || $record->id === null) {
                        return 'Cannot record review without submitted copy. Request via scoped correction.';
                    }

                    $currentReview = $record->preliminaryReviews->first(fn (PreliminaryEvidenceReview $review): bool => $review->successor === null);
                    if ($currentReview === null) {
                        return null;
                    }
                    if ($record->admissionApplication->currentPreliminaryReview($record) === null) {
                        return 'Earlier review is retained. This corrected source awaits Registrar review.';
                    }

                    $reviewerName = $currentReview->reviewer?->getFilamentName() ?? 'Reviewer unavailable';
                    $reviewedTime = $currentReview->reviewed_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A');

                    return collect([
                        $currentReview->reason,
                        "{$reviewerName} · {$reviewedTime}",
                    ])->filter()->implode("\n");
                }),
            Actions::make([
                Action::make('reviewEvidenceRow')
                    ->label('Review')
                    ->extraAttributes(fn (DocumentEvidence $record): array => ['aria-label' => e('Review '.($record->admissionRequirement?->label ?? 'evidence').' copy '.$record->id)])
                    ->button()
                    ->color('gray')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->visible(fn (DocumentEvidence $record): bool => (bool) $record->exists && $record->id !== null
                        && $record->admissionApplication?->application_state === AdmissionApplication::StateSubmitted
                        && $record->admissionApplication->reviewHasStarted()
                        && (bool) auth()->user()?->can('review', $record->admissionApplication)
                    )
                    ->action(fn (DocumentEvidence $record, ViewAdmissionApplication $livewire) => $livewire->replaceMountedAction('reviewEvidence', ['document_evidence_id' => $record->id])
                    ),
                Action::make('viewEvidence')->label('View')->iconButton()->tooltip('View copy')->color('gray')->icon('heroicon-o-eye')
                    ->extraAttributes(fn (DocumentEvidence $record): array => ['aria-label' => e('View '.($record->admissionRequirement?->label ?? 'evidence').' copy '.$record->id)])
                    ->visible(fn (DocumentEvidence $record): bool => (bool) $record->exists && $record->id !== null)
                    ->url(fn (DocumentEvidence $record): ?string => $record->id ? route('admissions.evidence.view', ['evidence' => $record]) : null)->openUrlInNewTab(),
                Action::make('downloadEvidence')->label('Download')->iconButton()->tooltip('Download copy')->color('gray')->icon('heroicon-o-arrow-down-tray')
                    ->extraAttributes(fn (DocumentEvidence $record): array => ['aria-label' => e('Download '.($record->admissionRequirement?->label ?? 'evidence').' copy '.$record->id)])
                    ->visible(fn (DocumentEvidence $record): bool => (bool) $record->exists && $record->id !== null)
                    ->url(fn (DocumentEvidence $record): ?string => $record->id ? route('admissions.evidence.download', ['evidence' => $record]) : null)->openUrlInNewTab(),
            ])->extraAttributes(['class' => 'tala-evidence-actions']),
        ];
    }
}
