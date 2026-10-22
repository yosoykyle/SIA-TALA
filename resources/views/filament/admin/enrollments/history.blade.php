@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php
    $date = fn ($value) => $value ? \Carbon\CarbonImmutable::parse($value)->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') : 'Date not recorded';
@endphp
<div class="registrar-case">
    <div class="registrar-case-identity">
        <h2>{{ $case->credentialUser?->getFilamentName() }}</h2>
        <p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }} · {{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }} · {{ $case->term?->label }}</p>
    </div>
    <div class="registrar-history-grid">
        <section aria-labelledby="activity-heading">
            <h2 id="activity-heading">Activity</h2>
            <p class="registrar-case-meta">Times in {{ config('app.display_timezone') }}</p>
            <ol class="registrar-case-events">
                @forelse ($case->registrationEvents->sortByDesc('sequence') as $event)
                    <li>
                        <time>{{ $date($event->recorded_at) }}</time>
                        <div>
                            <h3>{{ match ($event->event_type) {
                                'Started' => 'Enrollment started',
                                'RegistrationAdjustment' => 'Registered subjects changed',
                                'LateAuthorizedRegistrationAdjustment' => 'Late subject change recorded',
                                'CourseDrop' => 'Subject dropped',
                                'LateAuthorizedCourseDrop' => 'Late subject drop recorded',
                                'Reopened' => 'Enrollment reopened',
                                'ReopenImpactPreviewed' => 'Reopening reviewed',
                                'LateEnrollmentReopenAuthorityRecorded' => 'Late reopening approval recorded',
                                default => str($event->event_type)->headline(),
                            } }}</h3>
                            <p>{{ $event->actor?->getFilamentName() ?? 'System' }}</p>
                            @if ($event->reason)<p>{{ $event->reason === 'SelfService' ? 'Started online.' : $event->reason }}</p>@endif
                            @if ($event->authority_reference)<p class="registrar-case-meta">Reference: {{ $event->authority_reference }}</p>@endif
                        </div>
                    </li>
                @empty
                    <li>No enrollment activity is recorded.</li>
                @endforelse
            </ol>
            <details class="registrar-case-reference"><summary>Enrollment reference</summary><p>{{ $case->case_reference }}</p></details>
        </section>
        <aside class="registrar-case-sources registrar-history-records" aria-labelledby="sources-heading">
            <h2 id="sources-heading">Supporting records</h2>
            @foreach ($case->corVersions->sortByDesc('version') as $certificate)
                <section @if ($loop->first) id="certificate-copies" @endif>
                    <h3>{{ $case->current_cor_version_id === $certificate->id ? 'Current certificate' : 'Earlier certificate' }}</h3>
                    <p>Issued {{ $date($certificate->issued_at) }}</p>
                    <x-filament::link :href="route('cor.print', ['enrollment' => $case, 'version' => $certificate->version])" :aria-label="'Open '.($case->current_cor_version_id === $certificate->id ? 'current' : 'earlier').' certificate issued '.$date($certificate->issued_at).', copy '.$certificate->version" target="_blank" rel="noopener">Open certificate</x-filament::link>
                </section>
            @endforeach
            @if ($case->proposalVersions->isNotEmpty())
            <details class="registrar-case-reference"><summary><strong>Subject selections</strong><span>{{ $case->proposalVersions->count() }} {{ $case->proposalVersions->count() === 1 ? 'recorded selection' : 'recorded selections' }}</span></summary>
            @foreach ($case->proposalVersions->sortByDesc('version') as $version)
                <section>
                    <h3>{{ $case->current_proposal_version_id === $version->id ? 'Current selection' : 'Earlier selection' }}</h3>
                    <p>{{ match ($version->state) { 'Issued' => 'Sent for confirmation', 'Confirmed' => 'Confirmed by the student', 'Superseded' => 'Replaced by a later selection', default => 'Draft' } }} · {{ $date($version->issued_at ?? $version->prepared_at) }}</p>
                    @include('filament.admin.enrollments.subjects', ['proposal' => $version, 'sourceKey' => 'history-'.$version->id])
                    <details class="registrar-case-reference"><summary>Curriculum and confirmation</summary>
                        <p>{{ $version->curriculumVersion?->name ?? 'Curriculum not recorded' }}</p>
                        <p>{{ $version->selection_basis === \App\Models\Enrollment::SelectionIndividuallyAdvised ? 'Individually advised subjects' : 'Standard curriculum subjects' }}</p>
                        @if ($version->confirmation)
                            <p>Confirmed {{ $date($version->confirmation->confirmed_at) }}</p>
                            @if ($version->confirmation->assisted_evidence_reference)<p>Confirmation reference: {{ $version->confirmation->assisted_evidence_reference }}</p>@endif
                        @endif
                    </details>
                    @if ($version->timetableVersion)
                        @if ($version->timetableVersion->authority_reference)
                            <details class="registrar-case-reference"><summary>Schedule approval</summary><p>{{ $version->timetableVersion->authority_reference }}</p><p>Published {{ $date($version->timetableVersion->published_at) }}</p></details>
                        @endif
                    @endif
                </section>
            @endforeach
            </details>
            @endif
            @if ($case->termAccount?->assessments->isNotEmpty())
            <details class="registrar-case-reference"><summary><strong>Fee assessments</strong><span>Charges and school approvals</span></summary>
            @foreach (($case->termAccount?->assessments ?? collect())->sortByDesc('version') as $assessment)
                <section>
                    <h3>{{ $assessment->state === \App\Models\Assessment::StateActive ? 'Current assessment' : 'Earlier assessment' }}</h3>
                    <p>PHP {{ number_format((float) $assessment->total, 2) }} total charges</p>
                    <details class="registrar-case-reference"><summary>Assessment authorization</summary>
                        <p>{{ $assessment->authority_reference ?: 'Reference not recorded' }}</p><p>{{ $assessment->authority_date ? \Carbon\CarbonImmutable::parse($assessment->authority_date)->format('M j, Y') : 'Date not recorded' }}</p>
                        @if (data_get($assessment->source_snapshot, 'no_payment_required_authority_reference'))<p>No-payment authority: {{ data_get($assessment->source_snapshot, 'no_payment_required_authority_reference') }}</p>@endif
                        <dl class="registrar-source-charges">
                            @foreach ($assessment->lines as $line)
                                <div><dt>{{ $line->description_snapshot }}</dt><dd>PHP {{ number_format((float) $line->amount, 2) }}</dd></div>
                            @endforeach
                        </dl>
                    </details>
                </section>
            @endforeach
            </details>
            @endif
            @if ($case->admissionApplication?->application_path === \App\Models\AdmissionApplication::PathTransferee)
                <details class="registrar-case-reference"><summary><strong>Academic evaluations</strong><span>Recorded transfer credit decisions</span></summary>
                    @forelse ($this->academicEvaluations() as $evaluation)
                        <section><h3>{{ count($evaluation->credited_curriculum_entry_ids) === 0 ? 'No subjects credited' : count($evaluation->credited_curriculum_entry_ids).' credited subjects' }}</h3><p>Approved {{ $evaluation->approved_on->format('M j, Y') }}</p><p>Approval reference: {{ $evaluation->authority_reference }}</p><p>Recorded {{ $date($evaluation->recorded_at) }}</p></section>
                    @empty <p>No approved evaluation is recorded.</p> @endforelse
                    {{ $this->getAction('recordAcademicEvaluation', isMounting: false)->color('gray') }}
                </details>
            @endif
        </aside>
    </div>
</div>
