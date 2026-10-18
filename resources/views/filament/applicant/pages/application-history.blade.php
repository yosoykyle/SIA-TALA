<x-filament-panels::page>
    @php($application = $this->currentApplication())
    @if ($application)
        @php($projection = $this->readinessProjection($application))
        @php($currentDecision = $application->decisions->firstWhere('id', $projection['decision_id']))
    @endif

    <x-filament::section icon="heroicon-o-folder-open" icon-color="info">
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
                    <p class="tala-record-description">View the receipt or print the acknowledgment for each submitted version.</p>

                    <div class="space-y-3">
                        @foreach ($application->submissionVersions->sortByDesc('version') as $submissionVersion)
                            <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <p>
                                    <strong>Application version {{ $submissionVersion->version }}</strong> ·
                                    Requirement Set version {{ $submissionVersion->requirementSet?->version }} ·
                                    {{ $application->current_submission_version_id === $submissionVersion->id ? 'Current' : 'Historical and superseded' }}
                                </p>
                                <div class="flex flex-wrap gap-3">
                                    <x-filament::button
                                        :href="\App\Filament\Applicant\Pages\SubmissionReceipt::getUrl(['application' => $application->id, 'version' => $submissionVersion->id])"
                                        tag="a"
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-m-document-text"
                                    >
                                        View receipt
                                    </x-filament::button>
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
