<x-filament-panels::page>
    @include('filament-actions::components.modals')
    @php($application = $this->currentApplication())

    @if (! $application)
        <x-filament::section>
            <x-slot name="heading">No application yet</x-slot>
            <x-slot name="description">
                Start an application for an open admission cycle.
            </x-slot>

            @if ($this->admissionsAreOpen())
                <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application?->id])" tag="a" icon="heroicon-m-document-text">
                    Start application
                </x-filament::button>
            @else
                <x-filament::callout color="info" icon="heroicon-m-clock">
                    <x-slot name="heading">Applications are currently closed</x-slot>
                    <x-slot name="description">Check the school home page for the next admission cycle.</x-slot>
                </x-filament::callout>
            @endif
        </x-filament::section>
    @else
        @php($projection = $this->readinessProjection($application))

        @php($activeCorrection = $application->correctionRequests->where('state', \App\Models\ApplicationCorrectionRequest::StateActive)->sortByDesc('sequence')->first())
        @php($currentDecision = $application->decisions->firstWhere('id', $projection['decision_id']))
        @php($clearance = $application->enrollmentClearances->firstWhere('id', $projection['clearance_id']))
        <div class="space-y-6">
            <x-filament::section icon="heroicon-o-document-text" icon-color="info">
                <x-slot name="heading">{{ match ($application->application_state) {
                    \App\Models\AdmissionApplication::StateDraft => 'Your application draft',
                    \App\Models\AdmissionApplication::StateSubmitted => 'Your application is under review',
                    \App\Models\AdmissionApplication::StateActionNeeded => 'Corrections requested',
                    \App\Models\AdmissionApplication::StateAdmitted => $projection['ready'] ? 'Ready for enrollment' : 'Complete Registrar clearance',
                    \App\Models\AdmissionApplication::StateNotAdmitted => 'Admission decision recorded',
                    \App\Models\AdmissionApplication::StateWithdrawn => 'Application withdrawn',
                    default => 'Current application',
                } }}</x-slot>
                <div class="tala-application-summary">
                    <div class="tala-application-task-grid">
                    <div class="tala-application-task">
                        <x-filament::badge :color="$this->statusColor($application->application_state)">{{ $this->statusLabel($application->application_state) }}</x-filament::badge>
                    <p class="tala-application-next-action">{{ $this->nextAction($application) }}</p>
                    @if ($activeCorrection)
                        <p><strong>{{ $activeCorrection->isOverdue() ? 'Correction overdue:' : 'Correction due:' }}</strong> {{ $activeCorrection->due_at->timezone(config('app.display_timezone'))->format('M j, Y, g:i A') }} (Asia/Manila)<br>{{ $activeCorrection->applicant_instruction }}</p>
                    @elseif (($projection['clearance_result'] ?? null) === 'ActionNeeded')
                        <p>{{ collect($projection['blockers'])->firstWhere('source', 'Registrar enrollment clearance')['recovery'] ?? '' }}</p>
                    @endif
                    @if ($currentDecision?->applicant_explanation)
                        <p><strong>Admission decision remarks:</strong> {{ $currentDecision->applicant_explanation }}</p>
                    @endif
                    <div>
                        @if (in_array($application->application_state, [\App\Models\AdmissionApplication::StateDraft, \App\Models\AdmissionApplication::StateActionNeeded], true))
                            <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl(['application' => $application->id])" tag="a" icon="heroicon-m-pencil-square">
                                {{ $application->application_state === \App\Models\AdmissionApplication::StateDraft
                                    ? (app(\App\Queries\Admissions\ReadyApplicantProjectionQuery::class)->draftCycleIsOpen($application) ? 'Continue application' : 'Inspect saved draft')
                                    : 'Respond to correction' }}
                            </x-filament::button>
                        @else
                            <x-filament::button :href="\App\Filament\Applicant\Pages\Requirements::getUrl(['application' => $application->id])" tag="a" color="gray" icon="heroicon-m-clipboard-document-check">Review requirements</x-filament::button>
                        @endif
                    </div>
                    </div>
                    <dl class="tala-application-context-facts">
                        <div><dt>Responsible party</dt><dd>{{ $this->responsibleParty($application) }}</dd></div>
                        @if ($application->application_state === \App\Models\AdmissionApplication::StateDraft)
                            <div><dt>Applications close</dt><dd>{{ $application->admissionCycle?->closes_at?->timezone(config('app.display_timezone'))->format('M j, Y, g:i A') ?? 'Unavailable' }} (Asia/Manila)</dd></div>
                        @endif
                        <div><dt>Program and student type</dt><dd>{{ $application->program?->name ?? 'Program not selected' }}<small>{{ \App\Models\AdmissionCycle::studentTypeLabel($application->application_path) }}</small></dd></div>
                        <div><dt>Reference</dt><dd><x-application-reference :value="$application->application_reference" /></dd></div>
                    </dl>
                    </div>
                    <dl class="tala-journey-facts" aria-label="Application progress">
                        <div><dt><x-filament::icon icon="heroicon-o-document-check" class="size-5" />Preliminary copies</dt><dd>{{ ! $application->currentSubmissionVersion ? 'Not submitted' : (app(\App\Queries\Admissions\ReadyApplicantProjectionQuery::class)->preliminaryReviewIsComplete($application) ? 'Required copies accepted' : 'Review in progress') }}</dd></div>
                        <div><dt><x-filament::icon icon="heroicon-o-academic-cap" class="size-5" />Admission decision</dt><dd>{{ $currentDecision ? str($currentDecision->decision)->headline() : 'Pending' }}@if ($currentDecision)<small>{{ $currentDecision->decided_at?->timezone(config('app.display_timezone'))->format('M j, Y, g:i A') }} (Asia/Manila)</small>@endif</dd></div>
                        <div><dt><x-filament::icon icon="heroicon-o-clipboard-document-check" class="size-5" />Registrar clearance</dt><dd>{{ $projection['clearance_result'] ? str($projection['clearance_result'])->headline() : 'Pending' }}@if ($clearance)<small>{{ $clearance->recorded_at?->timezone(config('app.display_timezone'))->format('M j, Y, g:i A') }} (Asia/Manila)</small>@endif</dd></div>
                    </dl>
                </div>
            </x-filament::section>


            @php($registrationCase = $this->registrationCase())
            @php($registrationReadiness = $this->registrationReadiness())
            @if ($registrationCase && $registrationReadiness)
                <x-filament::section
                    heading="Enrollment checkpoints"
                    :description="($registrationCase->term?->label ?? 'Exact Term').' · '.$registrationCase->case_reference"
                    icon="heroicon-o-clipboard-document-check"
                >
                    @php($proposal = $registrationCase->currentProposalVersion)
                    @php($checkpointRows = [
                        ['Student eligibility', $registrationReadiness['eligibility'] && $registrationReadiness['identity'], 'Admissions and confirmed identity/contact source', 'Registrar', 'A stale or blocked source prevents the next enrollment action.', 'Contact the Registrar so the owning source can be corrected; no local override is created.'],
                        ['Confirmed proposed subjects', $registrationReadiness['confirmation'], 'Registration Proposal version '.($proposal?->version ?? 'not prepared'), 'Learner', 'Unconfirmed or superseded subjects cannot be placed or finalized.', 'Review and confirm the current issued proposal, or use attributable Registrar-assisted confirmation.'],
                        ['Valid class placement', $registrationReadiness['placement'], 'Published Timetable and reservations', 'Registrar', 'Only an affected course remains blocked; no waitlist or silent move is created.', 'The Registrar resolves the named prerequisite, class, capacity, conflict, or timetable-source blocker.'],
                        ['Accounting clearance', $registrationReadiness['finance'], 'Enrollment Payment Requirement', 'Accounting', 'Unavailable or unsatisfied current requirements block finalization only.', 'Accounting records the current assessment and valid payment or coverage result.'],
                        ['Registrar finalization', $registrationCase->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled, 'Atomic Registration Case result', 'Registrar', 'No Student activation, official roster, or COR exists until the transaction commits.', $registrationReadiness['ready'] ? 'All prior checkpoints are ready for Registrar finalization.' : 'Resolve the earlier named checkpoint, refresh current evidence, and retry.'],
                    ])
                    @php($currentCheckpoint = collect($checkpointRows)->search(fn ($row) => ! $row[1]))
                    <ol class="grid gap-3 lg:grid-cols-5">
                        @foreach ($checkpointRows as $index => [$label, $ready, $source, $owner, $consequence, $recovery])
                            <li class="rounded-xl bg-gray-50 p-4 dark:bg-white/5" @if ($currentCheckpoint === $index) aria-current="step" @endif>
                                <p class="font-semibold text-gray-950 dark:text-white">{{ $index + 1 }}. {{ $label }}</p>
                                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $ready ? 'Verified' : 'Action required' }} · Owner: {{ $owner }}</p>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Source: {{ $source }} · As of {{ $registrationCase->updated_at?->timezone(config('app.display_timezone'))->format('M d, Y g:i A') }}</p>
                                <p class="mt-2 text-xs text-gray-600 dark:text-gray-300">{{ $consequence }} {{ $recovery }}</p>
                            </li>
                        @endforeach
                    </ol>
                    <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
                        Selection basis: {{ str($registrationCase->selection_basis)->headline() }}. The authoritative Application source determines this basis; you do not choose it.
                    </p>
                </x-filament::section>
            @endif

        </div>
    @endif

    <x-filament::section collapsible collapsed icon="heroicon-o-folder-open" icon-color="info">
        <x-slot name="heading">Application details and history</x-slot>
        <div class="tala-record-groups">
            @if ($application)
            <div class="tala-record-group">
                <h3>Application details and earlier decisions</h3>
                <dl class="tala-status-grid">
                    <div class="tala-status-grid__item"><dt>Admission cycle</dt><dd>{{ $application->admissionCycle?->label ?? 'Unavailable' }}</dd></div>
                    <div class="tala-status-grid__item"><dt>Applications close</dt><dd>{{ $application->admissionCycle?->closes_at?->timezone(config('app.display_timezone'))->format('M j, Y, g:i A') ?? 'Unavailable' }} (Asia/Manila)</dd></div>
                    <div class="tala-status-grid__item"><dt>Submitted version</dt><dd>{{ $application->currentSubmissionVersion?->version ?? 'Not submitted' }}</dd></div>
                    <div class="tala-status-grid__item"><dt>Private evidence history</dt><dd>{{ $application->evidenceVersions->count() }} retained versions</dd></div>
                </dl>
                @foreach ($application->decisions->where('id', '!=', $currentDecision?->id)->sortByDesc('decided_at') as $decision)
                    <p class="mt-4"><strong>Superseded {{ str($decision->decision)->headline() }}</strong> · {{ $decision->decided_at?->timezone(config('app.display_timezone'))->format('M j, Y, g:i A') }} (Asia/Manila)<br>{{ $decision->applicant_explanation }}</p>
                @endforeach
            </div>
            @if ($application->submissionVersions->isNotEmpty())
                <div class="tala-record-group">
                    <h3>Application acknowledgment history</h3>
                    <p class="tala-record-description">Print or view the acknowledgment for each submitted version.</p>

                    <div class="space-y-3">
                        @foreach ($application->submissionVersions->sortByDesc('version') as $submissionVersion)
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <p>
                                    <strong>Application version {{ $submissionVersion->version }}</strong> ·
                                    Requirement Set version {{ $submissionVersion->requirementSet?->version }} ·
                                    {{ $application->current_submission_version_id === $submissionVersion->id ? 'Current' : 'Historical and superseded' }}
                                </p>
                                <x-filament::button
                                    :href="route('admissions.application.acknowledgment', ['application' => $application, 'version' => $submissionVersion])"
                                    tag="a"
                                    target="_blank"
                                    size="sm"
                                    icon="heroicon-m-printer"
                                >
                                    Open version {{ $submissionVersion->version }}
                                </x-filament::button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="tala-record-group">
                <h3>Application history</h3>
                @forelse ($application->events->sortByDesc('occurred_at') as $event)
                    <p><strong>{{ str($event->event_type)->headline() }}</strong> — {{ $event->occurred_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }} (Asia/Manila)</p>
                @empty
                    <p>No authoritative lifecycle event has been recorded yet.</p>
                @endforelse
            </div>
            @endif
            <div class="tala-record-group">
                <h3>Current and earlier applications</h3>
                {{ $this->table }}
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
