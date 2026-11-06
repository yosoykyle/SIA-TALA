@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php($date = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') : 'Date not recorded')
<div class="registrar-case tala-enrollment-record">
    @include('filament.admin.enrollments.record-header')
    <section class="tala-enrollment-ledger tala-task-surface" aria-labelledby="activity-heading">
        <header class="tala-enrollment-ledger-heading">
            <div><h2 id="activity-heading">Recorded changes</h2><p class="registrar-case-meta">Changes to this enrollment · Times in {{ config('app.display_timezone') }}</p></div>
            <p class="tala-enrollment-reference">Enrollment reference <strong>{{ $case->case_reference }}</strong></p>
        </header>
        @if ($case->registrationEvents->isNotEmpty())
            <ol class="tala-enrollment-events">
                @foreach ($case->registrationEvents->sortByDesc('sequence') as $event)
                    <li wire:key="enrollment-event-{{ $event->id }}">
                        <time>{{ $date($event->recorded_at) }}</time>
                        <div>
                            <h3>{{ match ($event->event_type) {
                                'Started', 'CaseStarted' => 'Enrollment started',
                                'RegistrationAdjustment' => 'Registered subjects changed',
                                'LateAuthorizedRegistrationAdjustment' => 'Late subject change recorded',
                                'CourseDrop' => 'Subject dropped',
                                'LateAuthorizedCourseDrop' => 'Late subject drop recorded',
                                'Reopened' => 'Enrollment reopened',
                                'ReopenImpactPreviewed' => 'Reopening reviewed',
                                'LateEnrollmentReopenAuthorityRecorded' => 'Late reopening approval recorded',
                                default => str($event->event_type)->headline(),
                            } }}</h3>
                            @if ($event->reason)<p>{{ $event->reason === 'SelfService' ? 'Started online.' : $event->reason }}</p>@endif
                            @if ($event->from_outcome !== $event->to_outcome && $event->to_outcome)
                                @if ($event->from_outcome)
                                    <x-record-change-comparison class="tala-enrollment-event-comparison" before-label="Previous status" after-label="Recorded status">
                                        <x-slot:before><strong>{{ str($event->from_outcome)->headline() }}</strong></x-slot:before>
                                        <x-slot:after><strong>{{ str($event->to_outcome)->headline() }}</strong></x-slot:after>
                                    </x-record-change-comparison>
                                @else
                                    <dl class="tala-enrollment-event-change"><div><dt>Recorded status</dt><dd>{{ str($event->to_outcome)->headline() }}</dd></div></dl>
                                @endif
                            @endif
                            @if ($event->authority_reference)<p class="registrar-case-meta">Reference: {{ $event->authority_reference }}</p>@endif
                        </div>
                        <p class="tala-enrollment-event-actor"><span>Recorded by</span>{{ $event->actor?->getFilamentName() ?? 'System' }}</p>
                    </li>
                @endforeach
            </ol>
        @else
            <x-filament::empty-state icon="heroicon-o-clock" class="tala-enrollment-empty">
                <x-slot:heading>No enrollment activity recorded</x-slot:heading>
                <x-slot:description>{{ $case->corVersions->isNotEmpty() || $case->proposalVersions->isNotEmpty() ? 'Recorded changes will appear here. Available certificates and saved subject selections are listed below.' : 'No changes or supporting records have been recorded for this enrollment.' }}</x-slot:description>
            </x-filament::empty-state>
        @endif
    </section>
    @if ($case->corVersions->isNotEmpty())
        <section class="tala-enrollment-ledger tala-task-surface" aria-labelledby="certificate-copies">
            <header class="tala-enrollment-ledger-heading"><div><h2 id="certificate-copies">Registration certificates</h2><p class="registrar-case-meta">Current and retained copies for this enrollment.</p></div></header>
            <ul class="tala-enrollment-documents">
                @foreach ($case->corVersions->sortByDesc('version') as $certificate)
                    <li>
                        <div><strong>{{ $case->current_cor_version_id === $certificate->id ? 'Current certificate' : 'Earlier certificate' }} · version {{ $certificate->version }}</strong><p>Issued {{ $date($certificate->issued_at) }}</p></div>
                        <x-filament::button color="gray" icon="heroicon-o-eye" tag="a" :href="route('cor.print', ['enrollment' => $case, 'version' => $certificate->version])" :aria-label="'Open certificate version '.$certificate->version" target="_blank" rel="noopener">View certificate</x-filament::button>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
    @if ($case->proposalVersions->isNotEmpty())
        <section class="tala-enrollment-ledger tala-task-surface" aria-labelledby="selections-heading">
            <header class="tala-enrollment-ledger-heading"><div><h2 id="selections-heading">Saved subject selections</h2><p class="registrar-case-meta">Selections are retained separately from official registered subjects.</p></div></header>
            <div class="tala-enrollment-selections">
                @foreach ($case->proposalVersions->sortByDesc('version') as $version)
                    <section wire:key="selection-history-{{ $version->id }}">
                        <div class="tala-enrollment-selection-heading"><h3>{{ $case->current_proposal_version_id === $version->id ? 'Current selection' : 'Earlier selection' }} · version {{ $version->version }}</h3><x-filament::badge color="gray">{{ match ($version->state) { 'Issued' => 'Sent for confirmation', 'Confirmed' => 'Confirmed by the student', 'Superseded' => 'Replaced by a later selection', default => 'Draft' } }}</x-filament::badge></div>
                        <p class="registrar-case-meta">{{ $date($version->issued_at ?? $version->prepared_at) }}</p>
                        @if ($version->items->isNotEmpty())
                            @include('filament.admin.enrollments.subjects', ['proposal' => $version, 'sourceKey' => 'history-'.$version->id])
                        @else
                            <p class="registrar-case-meta">No subjects recorded in this selection.</p>
                        @endif
                        <x-supporting-details summary="Curriculum and confirmation">
                            <dl class="tala-profile-facts">
                                <dt>Curriculum</dt><dd>{{ $version->curriculumVersion?->name ?? 'Curriculum not recorded' }}</dd>
                                <dt>Selection</dt><dd>{{ $version->selection_basis === \App\Models\Enrollment::SelectionIndividuallyAdvised ? 'Individually advised subjects' : 'Standard curriculum subjects' }}</dd>
                                @if ($version->confirmation)<dt>Confirmed</dt><dd>{{ $date($version->confirmation->confirmed_at) }}</dd>
                                    @if ($version->confirmation->assisted_evidence_reference)<dt>Confirmation reference</dt><dd>{{ $version->confirmation->assisted_evidence_reference }}</dd>@endif
                                @endif
                                @if ($version->timetableVersion?->authority_reference)<dt>Schedule approval</dt><dd>{{ $version->timetableVersion->authority_reference }}</dd><dt>Published</dt><dd>{{ $date($version->timetableVersion->published_at) }}</dd>@endif
                            </dl>
                        </x-supporting-details>
                    </section>
                @endforeach
            </div>
        </section>
    @endif
    @if ($case->termAccount?->assessments->isNotEmpty())
        <section class="tala-enrollment-ledger tala-task-surface" aria-labelledby="assessments-heading">
            <header class="tala-enrollment-ledger-heading"><h2 id="assessments-heading">Fee assessment history</h2></header>
            <div class="tala-enrollment-selections">
                @foreach ($case->termAccount->assessments->sortByDesc('version') as $assessment)
                    <section><div class="tala-enrollment-selection-heading"><h3>{{ $assessment->state === \App\Models\Assessment::StateActive ? 'Current assessment' : 'Earlier assessment' }}</h3><strong>PHP {{ number_format((float) $assessment->total, 2) }}</strong></div>
                        <x-supporting-details summary="Charges and supporting approval">
                            <p>{{ $assessment->authority_reference ?: 'Reference not recorded' }} · {{ $assessment->authority_date ? \Carbon\CarbonImmutable::parse($assessment->authority_date)->format('M j, Y') : 'Date not recorded' }}</p>
                            @if (data_get($assessment->source_snapshot, 'no_payment_required_authority_reference'))<p>No-payment authority: {{ data_get($assessment->source_snapshot, 'no_payment_required_authority_reference') }}</p>@endif
                            <dl class="registrar-source-charges">@foreach ($assessment->lines as $line)<div><dt>{{ $line->description_snapshot }}</dt><dd>PHP {{ number_format((float) $line->amount, 2) }}</dd></div>@endforeach</dl>
                        </x-supporting-details>
                    </section>
                @endforeach
            </div>
        </section>
    @endif
    @if ($case->admissionApplication?->application_path === \App\Models\AdmissionApplication::PathTransferee)
        <section class="tala-enrollment-ledger tala-task-surface" aria-labelledby="evaluations-heading">
            <header class="tala-enrollment-ledger-heading"><div><h2 id="evaluations-heading">Academic evaluations</h2><p class="registrar-case-meta">Recorded transfer credit decisions.</p></div>{{ $this->getAction('recordAcademicEvaluation', isMounting: false)->color('gray') }}</header>
            <div class="tala-enrollment-selections">@forelse ($this->academicEvaluations() as $evaluation)<section><h3>{{ count($evaluation->credited_curriculum_entry_ids) === 0 ? 'No subjects credited' : count($evaluation->credited_curriculum_entry_ids).' credited subjects' }}</h3><p>Approved {{ $evaluation->approved_on->format('M j, Y') }} · {{ $evaluation->authority_reference }}</p><p class="registrar-case-meta">Recorded {{ $date($evaluation->recorded_at) }}</p></section>@empty <p>No approved evaluation is recorded.</p> @endforelse</div>
        </section>
    @endif
</div>
