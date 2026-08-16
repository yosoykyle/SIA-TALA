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
                        <span><strong>Cycle closes:</strong> {{ $application->admissionCycle?->closes_at?->timezone(config('app.display_timezone'))->format('F j, Y, g:i A') ?? 'Unavailable' }}</span>
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

            <x-filament::section>
                <x-slot name="heading">What happens next</x-slot>
                <p>The Registrar reviews preliminary evidence, resolves any private identity warning, records an immutable admission decision, and then records official credential outcomes. Readiness appears automatically only when all current authoritative conditions pass.</p>
            </x-filament::section>

            @if ($application->currentSubmissionVersion)
                <x-filament::section>
                    <x-slot name="heading">Application acknowledgment</x-slot>
                    <x-slot name="description">Bound to Application version {{ $application->currentSubmissionVersion->version }} and Requirement Set version {{ $application->currentSubmissionVersion->requirementSet?->version }}.</x-slot>
                    <x-filament::button
                        :href="route('admissions.application.acknowledgment', ['application' => $application, 'version' => $application->currentSubmissionVersion])"
                        tag="a"
                        target="_blank"
                        icon="heroicon-m-printer"
                    >
                        Open acknowledgment
                    </x-filament::button>
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
