<?php

namespace App\Filament\Resources\AdmissionApplications\Schemas;

use App\Filament\Resources\AdmissionApplications\Pages\ViewAdmissionApplication;
use App\Models\AdmissionApplication;
use App\Models\AdmissionApplicationEvent;
use App\Models\AdmissionDecision;
use App\Models\AdmissionRequirement;
use App\Models\ApplicationCorrectionRequest;
use App\Models\ApplicationSubmissionVersion;
use App\Models\DocumentEvidence;
use App\Models\IdentityMatchReview;
use App\Models\OfficialCredentialResult;
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
            ->components([
                Tabs::make('ApplicantRecord')
                    ->tabs([
                        Tab::make('Current work')
                            ->icon('heroicon-o-clipboard-document-check')
                            ->schema([
                                Group::make([
                                    TextEntry::make('situation')
                                        ->label('Current task')
                                        ->weight('semibold')
                                        ->helperText(fn (AdmissionApplication $record): string => ($record->program?->name ?? 'Program unavailable').' · '.str($record->application_path)->headline())
                                        ->state(fn (AdmissionApplication $record): string => match ($record->application_state) {
                                            AdmissionApplication::StateDraft => 'Draft preparation — Applicant submission remains required',
                                            AdmissionApplication::StateSubmitted => 'Application received — Registrar review is pending',
                                            AdmissionApplication::StateActionNeeded => 'Applicant corrections are pending',
                                            AdmissionApplication::StateAdmitted => app(ReadyApplicantProjectionQuery::class)->forApplication($record)['ready']
                                                ? 'Admissions complete — ready for the enrollment journey'
                                                : 'Admission recorded — resolve the current readiness findings',
                                            AdmissionApplication::StateNotAdmitted => 'Review complete — Not admitted',
                                            AdmissionApplication::StateWithdrawn => 'Application withdrawn — retained for reference',
                                            default => 'Review the current application',
                                        })
                                        ->columnSpanFull(),
                                    TextEntry::make('responsible_party')
                                        ->label('Responsible party')
                                        ->state(fn (AdmissionApplication $record): string => app(ReadyApplicantProjectionQuery::class)->responsibleParty($record))
                                        ->columnSpan(['default' => 1, 'md' => 1]),
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
                                        ->columnSpan(['default' => 1, 'md' => 2]),
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
                                        ->columnSpanFull(),
                                ])
                                    ->columns(['default' => 1, 'md' => 3])
                                    ->extraAttributes(['class' => 'tala-registrar-current-task']),
                                Section::make('Preliminary evidence')
                                    ->schema([
                                        RepeatableEntry::make('evidenceVersions')
                                            ->hiddenLabel()
                                            ->state(fn (AdmissionApplication $record) => self::currentEvidence($record))
                                            ->table([
                                                TableColumn::make('Requirement'),
                                                TableColumn::make('Version / Uploaded'),
                                                TableColumn::make('Current review result'),
                                                TableColumn::make('Actions')->hiddenHeaderLabel(),
                                            ])
                                            ->schema(self::evidenceSchema())
                                            ->placeholder('No preliminary review copy submitted.')
                                            ->columnSpanFull(),
                                    ]),
                                Section::make('Supporting facts')
                                    ->compact()
                                    ->schema([
                                        TextEntry::make('application_reference')
                                            ->label('Application reference')
                                            ->copyable()
                                            ->weight('semibold'),
                                        TextEntry::make('submitted_at')->label('Form received')
                                            ->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))->placeholder('Not submitted'),
                                        TextEntry::make('current_decision')
                                            ->label('Admission decision')
                                            ->state(function (AdmissionApplication $record): string {
                                                $decision = $record->decisions->whereNull('successor')->sortByDesc('decided_at')->first();
                                                if ($decision === null) {
                                                    return match ($record->application_state) {
                                                        AdmissionApplication::StateDraft => 'Draft — not submitted',
                                                        AdmissionApplication::StateSubmitted => 'Undecided — review pending',
                                                        AdmissionApplication::StateActionNeeded => 'Undecided — correction pending',
                                                        AdmissionApplication::StateWithdrawn => 'None — withdrawn',
                                                        default => 'No decision recorded',
                                                    };
                                                }

                                                return str($decision->decision)->headline()->toString();
                                            })
                                            ->badge()
                                            ->color(function (AdmissionApplication $record): string {
                                                $decision = $record->decisions->whereNull('successor')->sortByDesc('decided_at')->first();
                                                if ($decision === null) {
                                                    return 'gray';
                                                }

                                                return match ($decision->decision) {
                                                    AdmissionDecision::DecisionAdmitted => 'success',
                                                    AdmissionDecision::DecisionNotAdmitted => 'danger',
                                                    default => 'info',
                                                };
                                            })
                                            ->helperText(function (AdmissionApplication $record): ?string {
                                                $decision = $record->decisions->whereNull('successor')->sortByDesc('decided_at')->first();
                                                if ($decision === null) {
                                                    return null;
                                                }

                                                $decider = $decision->decisionMaker?->getFilamentName() ?? 'Registrar';
                                                $time = $decision->decided_at->timezone(config('app.display_timezone'))->format('M j, Y · g:i A');
                                                $superseding = $decision->supersedes_admission_decision_id ? ' (superseding)' : '';

                                                $decisionVersion = $record->submissionVersions->firstWhere('id', $decision->application_submission_version_id);
                                                $versionNumber = $decisionVersion?->version ?? $decision->application_submission_version_id;
                                                $versionLabel = $versionNumber ? "Submission Version {$versionNumber}" : 'Submission version unavailable';

                                                $isCurrentSource = filled($decision->application_submission_version_id)
                                                    && (int) $decision->application_submission_version_id === (int) $record->current_submission_version_id;
                                                $sourceStatus = $isCurrentSource ? 'Current submitted version' : 'Earlier submitted version (retained)';

                                                return "Decided by {$decider} on {$time}{$superseding} · {$versionLabel} · {$sourceStatus}";
                                            }),
                                        TextEntry::make('current_clearance')
                                            ->label('Registrar clearance')
                                            ->visible(fn (AdmissionApplication $record): bool => $record->application_state === AdmissionApplication::StateAdmitted)
                                            ->state(fn (AdmissionApplication $record): string => app(ReadyApplicantProjectionQuery::class)->forApplication($record)['clearance_result'] ?? 'Awaiting current Registrar clearance')
                                            ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                                        TextEntry::make('admissionCycle.closes_at')
                                            ->label('Public closing')
                                            ->dateTime('M j, Y · g:i A')
                                            ->timezone(config('app.display_timezone')),
                                        TextEntry::make('correction_due')
                                            ->label('Active correction due')
                                            ->state(fn (AdmissionApplication $record): ?string => $record->correctionRequests
                                                ->where('state', ApplicationCorrectionRequest::StateActive)->sortByDesc('sequence')->first()
                                                ?->due_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A'))
                                            ->visible(fn (AdmissionApplication $record): bool => $record->correctionRequests->contains('state', ApplicationCorrectionRequest::StateActive)),
                                        TextEntry::make('derived_readiness')->label('Enrollment readiness')
                                            ->visible(fn (AdmissionApplication $record): bool => $record->application_state === AdmissionApplication::StateAdmitted)
                                            ->state(fn (AdmissionApplication $record): string => app(ReadyApplicantProjectionQuery::class)->forApplication($record)['ready']
                                                ? 'Ready from the current decision, identity and matching clearance. Official enrollment is separate.'
                                                : 'Awaiting current source-matching clearance or identity resolution. Follow the recorded school-check instructions.'),
                                    ])->columns(2),
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
                                Section::make('Identity review')
                                    ->schema([
                                        TextEntry::make('identity_warning')
                                            ->label('Identity integrity review')
                                            ->state(function (AdmissionApplication $record): string {
                                                $pending = $record->identityMatchReviews
                                                    ->where('outcome', IdentityMatchReview::OutcomePending)
                                                    ->count();

                                                return $pending > 0
                                                    ? "{$pending} private identity warning(s) require resolution before admission."
                                                    : 'No unresolved identity warning.';
                                            }),
                                    ])
                                    ->compact()
                                    ->collapsible()
                                    ->collapsed(fn (AdmissionApplication $record): bool => ! $record->identityMatchReviews->contains('outcome', IdentityMatchReview::OutcomePending)),
                            ]),
                        Tab::make('Applicant details')
                            ->icon('heroicon-o-user')
                            ->schema([
                                Section::make('Application & Intake')
                                    ->schema([
                                        TextEntry::make('admissionCycle.label')->label('Admission cycle'),
                                        TextEntry::make('term.label')->label('Target term'),
                                        TextEntry::make('program.name')->label('Program'),
                                        TextEntry::make('application_path')->label('Path')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                                    ])
                                    ->columns(2),
                                Section::make('Personal Details')
                                    ->schema([
                                        TextEntry::make('legal_name')
                                            ->label('Legal name')
                                            ->state(fn (AdmissionApplication $record): string => collect([
                                                $record->first_name,
                                                $record->middle_name,
                                                $record->last_name,
                                                $record->extension_name,
                                            ])->filter()->implode(' ')),
                                        TextEntry::make('email')->label('Verified account email'),
                                        TextEntry::make('birth_date')->date(),
                                        TextEntry::make('phone')->label('Mobile'),
                                        TextEntry::make('current_locality')
                                            ->label('Current locality')
                                            ->state(fn (AdmissionApplication $record): string => collect([
                                                $record->current_city_municipality,
                                                $record->current_province,
                                            ])->filter()->implode(', ')),
                                    ])
                                    ->columns(2),
                                Section::make('Prior Education')
                                    ->schema([
                                        TextEntry::make('prior_school_name')->label('Previous school attended'),
                                        TextEntry::make('credential_basis')->label('Educational attainment')
                                            ->formatStateUsing(fn (string $state): string => str($state)->lower()->headline()->toString()),
                                        TextEntry::make('prior_school_completion_year')->label('Graduation year'),
                                        TextEntry::make('lrn_availability')->label('LRN availability')->placeholder('Not recorded')
                                            ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                                        TextEntry::make('lrn')->label('LRN')->placeholder('No identifier provided'),
                                    ])
                                    ->columns(2),
                                Section::make('Emergency Contact')
                                    ->schema([
                                        TextEntry::make('guardian_full_name')->label('Parent, guardian or emergency contact')->placeholder('Not provided'),
                                        TextEntry::make('guardian_relationship')->label('Relationship')->placeholder('Not provided'),
                                        TextEntry::make('guardian_mobile')->label('Contact mobile')->placeholder('Not provided'),
                                    ])
                                    ->columns(2),
                            ]),
                        Tab::make('Record history')
                            ->icon('heroicon-o-clock')
                            ->schema([
                                RepeatableEntry::make('submissionVersions')
                                    ->label('Submitted versions')
                                    ->state(fn (AdmissionApplication $record) => $record->submissionVersions->sortByDesc('version')->values())
                                    ->placeholder('No submitted version yet. The saved Draft remains in Applicant details.')
                                    ->contained(false)
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('version')->label('Version')
                                            ->formatStateUsing(fn (int $state): string => 'Submitted version '.$state)
                                            ->helperText(fn (ApplicationSubmissionVersion $record): string => (int) $record->id === (int) $record->application->current_submission_version_id ? 'Current submitted source' : 'Earlier retained source')
                                            ->url(fn (ApplicationSubmissionVersion $record, ViewAdmissionApplication $livewire): string => route('admissions.application.acknowledgment', ['application' => $record->admission_application_id, 'version' => $record->id, 'queue' => $livewire->queueContext()]))
                                            ->openUrlInNewTab(),
                                        TextEntry::make('submitter.name')->label('Submitted by')
                                            ->state(fn (ApplicationSubmissionVersion $record): string => $record->submitter?->getFilamentName() ?? 'Applicant unavailable')
                                            ->helperText(fn (ApplicationSubmissionVersion $record): ?string => $record->submitted_at?->timezone(config('app.display_timezone'))->format('M j, Y - g:i A')),
                                    ]),
                                Section::make('Earlier evidence versions')
                                    ->schema([
                                        RepeatableEntry::make('earlier_evidence')
                                            ->hiddenLabel()
                                            ->state(fn (AdmissionApplication $record) => $record->evidenceVersions->whereNotIn('id', self::currentEvidence($record)->pluck('id')->filter()->all())->values())
                                            ->table([
                                                TableColumn::make('Requirement'),
                                                TableColumn::make('Version / Uploaded'),
                                                TableColumn::make('Review result'),
                                                TableColumn::make('Actions')->hiddenHeaderLabel(),
                                            ])
                                            ->schema(self::evidenceSchema())
                                            ->columnSpanFull(),
                                    ])
                                    ->visible(fn (AdmissionApplication $record): bool => $record->evidenceVersions->whereNotIn('id', self::currentEvidence($record)->pluck('id')->filter()->all())->isNotEmpty())
                                    ->collapsible()
                                    ->collapsed(),
                                Section::make('Superseded preliminary reviews')
                                    ->schema([
                                        TextEntry::make('superseded_reviews')
                                            ->hiddenLabel()
                                            ->state(function (AdmissionApplication $record): string {
                                                $superseded = $record->evidenceVersions
                                                    ->flatMap->preliminaryReviews
                                                    ->filter(fn (PreliminaryEvidenceReview $review): bool => $review->successor !== null)
                                                    ->sortByDesc('reviewed_at');

                                                if ($superseded->isEmpty()) {
                                                    return 'No superseded preliminary reviews.';
                                                }

                                                return $superseded->map(fn (PreliminaryEvidenceReview $review): string => sprintf(
                                                    '%s — %s — %s (%s)%s',
                                                    $review->documentEvidence->admissionRequirement->label,
                                                    $review->result === PreliminaryEvidenceReview::ResultAccepted ? 'Accepted' : str($review->result)->headline(),
                                                    $review->reviewer?->getFilamentName() ?? 'Reviewer unavailable',
                                                    $review->reviewed_at?->timezone(config('app.display_timezone'))->format('M j, Y g:i A'),
                                                    filled($review->reason) ? ' — '.$review->reason : '',
                                                ))->implode("\n");
                                            })
                                            ->listWithLineBreaks(),
                                    ])
                                    ->visible(fn (AdmissionApplication $record): bool => $record->evidenceVersions->flatMap->preliminaryReviews->contains(fn (PreliminaryEvidenceReview $review): bool => $review->successor !== null))
                                    ->collapsible()
                                    ->collapsed(),
                                Section::make('Decisions and clearance')
                                    ->compact()
                                    ->schema([
                                        TextEntry::make('decision_history_empty')->hiddenLabel()
                                            ->state('No admission decision recorded. Review the current application before recording an outcome.')
                                            ->visible(fn (AdmissionApplication $record): bool => $record->decisions->isEmpty()),
                                        RepeatableEntry::make('decisions')
                                            ->label('Admission decisions')
                                            ->state(fn (AdmissionApplication $record) => $record->decisions->sortByDesc('decided_at')->values())
                                            ->visible(fn (AdmissionApplication $record): bool => $record->decisions->isNotEmpty())
                                            ->contained(false)
                                            ->columns(2)
                                            ->schema([
                                                TextEntry::make('decision')->label('Outcome')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                                                    ->weight('semibold')
                                                    ->helperText(fn (AdmissionDecision $record): string => $record->successor !== null
                                                        ? 'Superseded by a later decision' : 'Current decision'),
                                                TextEntry::make('decisionMaker.name')->label('Recorded by')
                                                    ->state(fn (AdmissionDecision $record): string => $record->decisionMaker?->getFilamentName() ?? 'Registrar unavailable')
                                                    ->helperText(fn (AdmissionDecision $record): ?string => $record->decided_at?->timezone(config('app.display_timezone'))->format('M j, Y - g:i A')),
                                                TextEntry::make('decision_source')->label('Submitted source')
                                                    ->state(fn (AdmissionDecision $record): string => self::submissionLabel($record->application, $record->application_submission_version_id))
                                                    ->helperText(fn (AdmissionDecision $record): ?string => $record->supersededDecision === null ? null
                                                        : 'Replaces '.str($record->supersededDecision->decision)->headline().' recorded '.$record->supersededDecision->decided_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A')),
                                                TextEntry::make('reason')->label('Private review basis')
                                                    ->helperText(fn (AdmissionDecision $record): string => filled($record->authority_reference)
                                                        ? 'Approval reference: '.$record->authority_reference : 'Recorded Registrar authority'),
                                                TextEntry::make('applicant_explanation')->label('Applicant explanation')->columnSpanFull(),
                                            ]),
                                        TextEntry::make('clearance_history_empty')->hiddenLabel()
                                            ->state('No enrollment clearance recorded. Clearance follows an Admitted decision and the school\'s external checks.')
                                            ->visible(fn (AdmissionApplication $record): bool => $record->enrollmentClearances->isEmpty()),
                                        RepeatableEntry::make('enrollmentClearances')
                                            ->label('Registrar enrollment clearance')
                                            ->state(fn (AdmissionApplication $record) => $record->enrollmentClearances->sortByDesc('recorded_at')->values())
                                            ->visible(fn (AdmissionApplication $record): bool => $record->enrollmentClearances->isNotEmpty())
                                            ->contained(false)
                                            ->columns(2)
                                            ->schema([
                                                TextEntry::make('result')->label('Outcome')->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())
                                                    ->weight('semibold')
                                                    ->helperText(fn (RegistrarEnrollmentClearance $record): string => $record->successor !== null
                                                        ? 'Superseded by a later clearance' : 'Latest clearance; current source matching determines readiness'),
                                                TextEntry::make('recorder.name')->label('Recorded by')
                                                    ->state(fn (RegistrarEnrollmentClearance $record): string => $record->recorder?->getFilamentName() ?? 'Registrar unavailable')
                                                    ->helperText(fn (RegistrarEnrollmentClearance $record): ?string => $record->recorded_at?->timezone(config('app.display_timezone'))->format('M j, Y - g:i A')),
                                                TextEntry::make('clearance_source')->label('Submitted source')
                                                    ->state(fn (RegistrarEnrollmentClearance $record): string => self::submissionLabel($record->application, $record->application_submission_version_id))
                                                    ->helperText(fn (RegistrarEnrollmentClearance $record): string => $record->supersedes_clearance_id === null
                                                        ? 'First clearance' : 'Replaces an earlier retained clearance'),
                                                TextEntry::make('reason')->label('Private review basis')
                                                    ->helperText(fn (RegistrarEnrollmentClearance $record): string => filled($record->authority_reference)
                                                        ? 'Supporting approval reference: '.$record->authority_reference : 'Recorded Registrar authority'),
                                                TextEntry::make('safe_instruction')->label('Applicant instruction')->placeholder('No additional instruction')->columnSpanFull(),
                                            ]),
                                    ]),
                                Section::make('Retained credential history')
                                    ->schema([
                                        TextEntry::make('credential_history')
                                            ->label('Current and historical results')
                                            ->state(fn (AdmissionApplication $record): string => $record->credentialResults
                                                ->sortByDesc('recorded_at')
                                                ->map(fn (OfficialCredentialResult $result): string => sprintf(
                                                    '%s — %s — %s',
                                                    $result->requirement->label,
                                                    str($result->result)->headline(),
                                                    $result->recorded_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A'),
                                                ))
                                                ->implode("\n") ?: 'No official credential result recorded.')
                                            ->listWithLineBreaks(),
                                    ])
                                    ->visible(fn (AdmissionApplication $record): bool => $record->credentialResults->isNotEmpty())
                                    ->collapsible()
                                    ->collapsed(),
                                Section::make('Correction request history')
                                    ->schema([
                                        TextEntry::make('correction_history')
                                            ->label('Correction requests')
                                            ->state(fn (AdmissionApplication $record): string => $record->correctionRequests
                                                ->sortByDesc('created_at')
                                                ->map(fn (ApplicationCorrectionRequest $request): string => sprintf(
                                                    '%s — Sequence #%d — State: %s — Due: %s%s',
                                                    $request->created_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A'),
                                                    $request->sequence,
                                                    str($request->state)->headline(),
                                                    $request->due_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A'),
                                                    ' — Requested by '.($request->requester?->getFilamentName() ?? 'Registrar unavailable').' — '.$request->applicant_instruction,
                                                ))
                                                ->implode("\n") ?: 'No correction request recorded.')
                                            ->listWithLineBreaks(),
                                    ])
                                    ->visible(fn (AdmissionApplication $record): bool => $record->correctionRequests->isNotEmpty())
                                    ->compact(),
                                Section::make('Activity, delivery, and technical evidence')
                                    ->schema([
                                        TextEntry::make('submitted_at')->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone'))->placeholder('Not submitted'),
                                        TextEntry::make('updated_at')->label('Last record update')->dateTime('M j, Y · g:i A')->timezone(config('app.display_timezone')),
                                        TextEntry::make('currentSubmissionVersion.version')->label('Current submitted version')->placeholder('None'),
                                        TextEntry::make('currentSubmissionVersion.requirementSet.version')->label('Requirement Set version')->placeholder('None'),
                                        TextEntry::make('id')->label('Internal application ID'),
                                        RepeatableEntry::make('events')->label('Record events')
                                            ->state(fn (AdmissionApplication $record) => $record->events->sortByDesc('occurred_at')->values())
                                            ->contained(false)->columns(2)->columnSpanFull()
                                            ->placeholder('No recorded event yet.')
                                            ->schema([
                                                TextEntry::make('event_type')->label('Event')
                                                    ->formatStateUsing(fn (string $state): string => str($state)->headline()->toString()),
                                                TextEntry::make('actor.name')->label('Actor')
                                                    ->state(fn (AdmissionApplicationEvent $record): string => $record->actor?->getFilamentName() ?? 'System')
                                                    ->helperText(fn (AdmissionApplicationEvent $record): ?string => $record->occurred_at?->timezone(config('app.display_timezone'))->format('M j, Y · g:i A')),
                                                TextEntry::make('event_source')->label('Technical source')
                                                    ->state(fn (AdmissionApplicationEvent $record): string => class_basename($record->source_type).' #'.$record->source_id),
                                            ]),
                                    ])
                                    ->columns(2)
                                    ->collapsible()
                                    ->collapsed(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    private static function submissionLabel(AdmissionApplication $application, ?int $submissionId): string
    {
        $version = $application->submissionVersions->firstWhere('id', $submissionId);
        if ($version === null) {
            return 'Submitted source unavailable';
        }

        return 'Submitted version '.$version->version.((int) $submissionId === (int) $application->current_submission_version_id
            ? ' - current source' : ' - earlier retained source');
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

        $remaining = $application->evidenceVersions
            ->sortByDesc('id')
            ->unique('admission_requirement_id')
            ->reject(fn (DocumentEvidence $evidence) => $coveredRequirementIds->contains($evidence->admission_requirement_id));

        return new Collection($rows->concat($remaining)->all());
    }

    private static function evidenceSchema(): array
    {
        return [
            TextEntry::make('admissionRequirement.label')->weight('semibold'),
            TextEntry::make('version_status')
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
            TextEntry::make('review_history')
                ->state(function (DocumentEvidence $record): string {
                    if (! $record->exists || $record->id === null) {
                        return 'Missing preliminary copy';
                    }

                    $currentReview = $record->preliminaryReviews->first(fn (PreliminaryEvidenceReview $review): bool => $review->successor === null);

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

                    $currentReview = $record->preliminaryReviews->first(fn (PreliminaryEvidenceReview $review): bool => $review->successor === null);

                    return match ($currentReview?->result) {
                        PreliminaryEvidenceReview::ResultAccepted => 'success',
                        PreliminaryEvidenceReview::ResultActionNeeded => 'danger',
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

                    $reviewerName = $currentReview->reviewer?->getFilamentName() ?? 'Reviewer unavailable';
                    $reviewedTime = $currentReview->reviewed_at->timezone(config('app.display_timezone'))->format('M j, Y g:i A');

                    return collect([
                        $currentReview->reason,
                        "{$reviewerName} · {$reviewedTime}",
                    ])->filter()->implode("\n");
                }),
            Actions::make([
                Action::make('viewEvidence')->label('View')->button()->color('gray')->icon('heroicon-o-eye')
                    ->extraAttributes(fn (DocumentEvidence $record): array => ['aria-label' => e('View '.($record->admissionRequirement?->label ?? 'evidence').' copy '.$record->id)])
                    ->visible(fn (DocumentEvidence $record): bool => (bool) $record->exists && $record->id !== null)
                    ->url(fn (DocumentEvidence $record): ?string => $record->id ? route('admissions.evidence.view', ['evidence' => $record]) : null)->openUrlInNewTab(),
                Action::make('reviewEvidenceRow')
                    ->label('Review')
                    ->extraAttributes(fn (DocumentEvidence $record): array => ['aria-label' => e('Review '.($record->admissionRequirement?->label ?? 'evidence').' copy '.$record->id)])
                    ->button()
                    ->color('gray')
                    ->icon('heroicon-o-document-magnifying-glass')
                    ->visible(fn (DocumentEvidence $record): bool => (bool) $record->exists && $record->id !== null
                        && $record->admissionApplication?->application_state === AdmissionApplication::StateSubmitted
                        && (bool) auth()->user()?->can('review', $record->admissionApplication)
                    )
                    ->action(fn (DocumentEvidence $record, ViewAdmissionApplication $livewire) => $livewire->replaceMountedAction('reviewEvidence', ['document_evidence_id' => $record->id])
                    ),
                Action::make('downloadEvidence')->label('Download')->button()->color('gray')->icon('heroicon-o-arrow-down-tray')
                    ->extraAttributes(fn (DocumentEvidence $record): array => ['aria-label' => e('Download '.($record->admissionRequirement?->label ?? 'evidence').' copy '.$record->id)])
                    ->visible(fn (DocumentEvidence $record): bool => (bool) $record->exists && $record->id !== null)
                    ->url(fn (DocumentEvidence $record): ?string => $record->id ? route('admissions.evidence.download', ['evidence' => $record]) : null)->openUrlInNewTab(),
            ]),
        ];
    }
}
