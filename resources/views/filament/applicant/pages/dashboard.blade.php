<x-filament-panels::page>
    @php($application = $this->currentApplication())

    @if (! $application)
        <x-filament::section>
            <x-slot name="heading">No application yet</x-slot>
            <x-slot name="description">
                One verified account can own one Application per published Admission Cycle.
            </x-slot>

            @if ($this->admissionsAreOpen())
                <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl()" tag="a" icon="heroicon-m-document-text">
                    Start application
                </x-filament::button>
            @else
                <x-filament::callout color="info" icon="heroicon-m-clock">
                    <x-slot name="heading">Applications are currently closed</x-slot>
                    <x-slot name="description">Applicant sign-in remains available. Check the public TALA gateway for the next published Cycle.</x-slot>
                </x-filament::callout>
            @endif
        </x-filament::section>
    @else
        @php($projection = $this->readinessProjection($application))

        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">
                    {{ $application->application_reference ?? 'Application draft' }}
                </x-slot>
                <x-slot name="description">
                    State, accountable party, nearest Cycle deadline, and one safe next action.
                </x-slot>

                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::badge :color="$this->statusColor($application->application_state)" size="lg">
                            {{ $this->statusLabel($application->application_state) }}
                        </x-filament::badge>
                        <span><strong>Responsible party:</strong> {{ $this->responsibleParty($application) }}</span>
                        <span><strong>Public closing:</strong> {{ $application->admissionCycle?->closes_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') ?? 'Unavailable' }}</span>
                        @php($activeCorrection = $application->correctionRequests->where('state', \App\Models\ApplicationCorrectionRequest::StateActive)->sortByDesc('sequence')->first())
                        @if ($activeCorrection)
                            <span><strong>{{ $activeCorrection->isOverdue() ? 'Correction overdue:' : 'Correction due:' }}</strong> {{ $activeCorrection->due_at->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</span>
                        @endif
                    </div>

                    <x-filament::callout color="info" icon="heroicon-m-arrow-right-circle">
                        <x-slot name="heading">What you need to do next</x-slot>
                        <x-slot name="description">{{ $this->nextAction($application) }}</x-slot>
                    </x-filament::callout>

                    <div class="tala-action-block">
                        @if (in_array($application->application_state, [\App\Models\AdmissionApplication::StateDraft, \App\Models\AdmissionApplication::StateActionNeeded], true))
                            <x-filament::button :href="\App\Filament\Applicant\Pages\Application::getUrl()" tag="a" icon="heroicon-m-pencil-square">
                                {{ $application->application_state === \App\Models\AdmissionApplication::StateDraft ? 'Continue application' : 'Respond to correction' }}
                            </x-filament::button>
                        @else
                            <x-filament::button :href="\App\Filament\Applicant\Pages\Requirements::getUrl()" tag="a" icon="heroicon-m-clipboard-document-check">
                                Review requirements
                            </x-filament::button>
                        @endif
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Application scope</x-slot>
                <dl class="tala-status-grid">
                    <div class="tala-status-grid__item"><dt>Admission Cycle</dt><dd>{{ $application->admissionCycle?->label ?? 'Unavailable' }}</dd></div>
                    <div class="tala-status-grid__item"><dt>Program</dt><dd>{{ $application->program?->name ?? 'Not selected' }}</dd></div>
                    <div class="tala-status-grid__item"><dt>Path</dt><dd>{{ str($application->application_path)->headline() }}</dd></div>
                    <div class="tala-status-grid__item"><dt>Current submitted version</dt><dd>{{ $application->currentSubmissionVersion?->version ?? 'Not submitted' }}</dd></div>
                </dl>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Preliminary evidence readiness</x-slot>
                <p>
                    {{ $application->evidenceVersions->count() }} private evidence version(s) are retained.
                    Preliminary review never means an official credential is verified.
                </p>
            </x-filament::section>

            @php($currentDecision = $application->decisions->first(
                fn ($decision) => ! $application->decisions->contains(
                    'supersedes_admission_decision_id',
                    $decision->id,
                ),
            ))
            @if ($currentDecision)
                <x-filament::section>
                    <x-slot name="heading">Current admission outcome</x-slot>
                    <x-slot name="description">The current attributable result is shown first; superseded outcomes remain labelled in history.</x-slot>

                    <dl class="tala-status-grid">
                        <div class="tala-status-grid__item"><dt>Result</dt><dd>{{ str($currentDecision->decision)->headline() }}</dd></div>
                        <div class="tala-status-grid__item"><dt>Responsible office</dt><dd>Registrar</dd></div>
                        <div class="tala-status-grid__item"><dt>Decision date</dt><dd>{{ $currentDecision->decided_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') ?? 'Unavailable' }}</dd></div>
                        <div class="tala-status-grid__item"><dt>Consequence and next action</dt><dd>{{ $this->nextAction($application) }}</dd></div>
                    </dl>
                    <x-filament::callout :color="$currentDecision->decision === \App\Models\AdmissionDecision::DecisionAdmitted ? 'success' : 'info'" icon="heroicon-m-information-circle">
                        <x-slot name="heading">Registrar explanation</x-slot>
                        <x-slot name="description">{{ $currentDecision->applicant_explanation }}</x-slot>
                    </x-filament::callout>

                    @if ($application->decisions->count() > 1)
                        <details class="mt-4">
                            <summary>Show superseded admission outcomes</summary>
                            @foreach ($application->decisions->where('id', '!=', $currentDecision->id)->sortByDesc('decided_at') as $decision)
                                <p><strong>Superseded {{ str($decision->decision)->headline() }}</strong> — {{ $decision->decided_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }} — {{ $decision->applicant_explanation }}</p>
                            @endforeach
                        </details>
                    @endif
                </x-filament::section>
            @endif

            <x-filament::section>
                <x-slot name="heading">Official credential readiness</x-slot>
                @if (($projection['ready'] ?? false) === true)
                    <x-filament::callout color="success" icon="heroicon-m-check-circle">
                        <x-slot name="heading">Ready for enrollment</x-slot>
                        <x-slot name="description">This is a derived read-only projection. It did not create a Student, enrollment, assessment, or Registration Case.</x-slot>
                    </x-filament::callout>
                @else
                    <p>{{ count($projection['blockers'] ?? []) }} current readiness blocker(s). Follow only the Applicant-safe instruction shown in Requirements.</p>
                @endif
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

            <x-filament::section>
                <x-slot name="heading">What happens next</x-slot>
                <p>The Registrar reviews preliminary evidence, resolves any private identity warning, records an immutable admission decision, and then records official credential outcomes. Readiness appears automatically only when all current authoritative conditions pass.</p>
            </x-filament::section>

            @if ($application->submissionVersions->isNotEmpty())
                <x-filament::section>
                    <x-slot name="heading">Application acknowledgment history</x-slot>
                    <x-slot name="description">Each printable acknowledgment remains bound to its immutable Application and Requirement Set versions.</x-slot>

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
                </x-filament::section>
            @endif

            <x-filament::section collapsible>
                <x-slot name="heading">Application history</x-slot>
                @forelse ($application->events->sortByDesc('occurred_at') as $event)
                    <p><strong>{{ str($event->event_type)->headline() }}</strong> — {{ $event->occurred_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') }}</p>
                @empty
                    <p>No authoritative lifecycle event has been recorded yet.</p>
                @endforelse
            </x-filament::section>
        </div>
    @endif

    <x-filament::section>
        <x-slot name="heading">Current and earlier Applications</x-slot>
        {{ $this->table }}
    </x-filament::section>
</x-filament-panels::page>
