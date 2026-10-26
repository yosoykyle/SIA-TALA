@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        <p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }}</p>
        <p>{{ $proposal->curriculumVersion?->program?->name ?? $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <section class="registrar-case-progress-task" aria-labelledby="progress-heading">
        <div>
            <h2 id="progress-heading">{{ $taskTitle }}</h2>
            @if ($progressView === 'assessment-recovery')
                <p>Accounting must resolve the current assessment for these reserved subjects.@if ($proposal->confirmation === null) The student can confirm when the charges are available.@else Student confirmation remains recorded.@endif</p>
            @elseif ($progressView === 'placement-recovery')
                <p>{{ $canReserve ? 'Check current capacity and schedule before reserving these classes again.' : 'Prepare updated subjects using the current timetable, eligibility and available classes.' }}</p>
                @if ($proposal->confirmation !== null)<p class="registrar-case-meta">Student confirmation remains recorded. A changed subject selection requires a new confirmation.</p>@endif
                @if ($readiness['shortages'] !== [])
                    <ul class="registrar-case-shortages">
                        @foreach ($readiness['shortages'] as $shortage)
                            <li><strong>{{ $shortage['course'] }} · {{ $shortage['section'] }}</strong><p>No available seats.@if ($shortage['alternatives'] !== []) Alternatives: {{ implode(', ', $shortage['alternatives']) }}.@endif</p></li>
                        @endforeach
                    </ul>
                @endif
                @if ($canReserve){{ $this->getAction('prepareProposal', isMounting: false)->link()->color('gray') }}@endif
            @else
                <p>{{ $paymentReview ? 'A payment needs Accounting review. The enrollment requirement remains open until the review records a verified payment or approved coverage.' : 'The student confirmed these subjects. Accounting reviews the enrollment payment or approved coverage before Registrar can finalize.' }}</p>
            @endif
            @if ($reservationDeadline)
                <p class="registrar-case-meta">Seats held until {{ \App\Support\DisplayDateTime::format($reservationDeadline) }} · {{ config('app.display_timezone') }}</p>
            @endif
            @if ($progressView === 'assessment-recovery')
                <x-workflow-handoff current-owner="Accounting" current-task="Review charges" next-owner="Registrar" next-task="Continue enrollment review" current-icon="heroicon-o-banknotes" next-icon="heroicon-o-clipboard-document-check" />
            @elseif ($progressView !== 'placement-recovery' && $paymentReview)
                <x-workflow-handoff current-owner="Accounting" current-task="Review payment" next-owner="Registrar" next-task="Review enrollment clearance" current-icon="heroicon-o-banknotes" next-icon="heroicon-o-clipboard-document-check" />
            @endif
        </div>
        @if ($progressView === 'placement-recovery')
            {{ $this->getAction($canReserve ? 'placeProposal' : 'prepareProposal', isMounting: false) }}
        @endif
    </section>
    <div class="registrar-case-progress-grid">
        <section aria-labelledby="progress-subjects-heading">
            <h2 id="progress-subjects-heading">{{ $progressView === 'placement-recovery' ? 'Subjects to review' : ($proposal->confirmation !== null ? 'Confirmed subjects' : 'Proposed subjects') }}</h2>
            <p class="registrar-case-meta">{{ $proposal->items->count() }} subjects · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'progress'])
        </section>
        <section class="registrar-case-payment registrar-case-progress-payment tala-task-surface" aria-labelledby="progress-payment-heading">
            <h2 id="progress-payment-heading">Enrollment payment</h2>
            @if ($requirement['state'] === 'Unavailable')
                <h3>Current assessment unavailable</h3>
                <p>Accounting must check the assessment source before amounts or clearance can be shown.</p>
                <p class="registrar-case-meta">Earlier charges remain in Enrollment history.</p>
            @else
                <p class="registrar-case-amount">{{ $requirement['state'] === 'Cleared' ? 'Cleared' : 'PHP '.number_format((float) $requirement['balance'], 2) }}</p>
                <p>{{ $requirement['state'] === 'Cleared' ? 'The enrollment payment requirement is satisfied.' : 'Still needed for enrollment' }}</p>
                <dl>
                    <div><dt>Required for enrollment</dt><dd>PHP {{ number_format((float) $requirement['total'], 2) }}</dd></div>
                    @if ($assessment !== null)<div><dt>Total term charges</dt><dd>PHP {{ number_format((float) $assessment->total, 2) }}</dd></div>@endif
                    @if ((float) $requirement['payment_applied'] > 0)<div><dt>Verified payment applied</dt><dd>PHP {{ number_format((float) $requirement['payment_applied'], 2) }}</dd></div>@endif
                    @if ((float) $requirement['coverage_applied'] > 0)<div><dt>Approved coverage applied</dt><dd>PHP {{ number_format((float) $requirement['coverage_applied'], 2) }}</dd></div>@endif
                </dl>
                <p class="registrar-case-meta">Accounting reviews payments and approved coverage.@if ($requirement['later_obligation']) Later term payments remain separate.@endif</p>
            @endif
        </section>
    </div>
    <details class="registrar-case-reference registrar-case-progress-schedule">
        <summary>Proposed schedule</summary>
        @if ($schedule && $schedule['meetings'] !== [])
            <x-enrollment-schedule :presentation="$schedule" :source-key="'registrar-progress-'.$proposal->id" />
        @else
            <p>These subjects have no recurring meetings. Check the arranged schedule with the responsible office.</p>
        @endif
    </details>
    <a class="registrar-case-history-link" href="{{ $this->enrollmentUrl(['view' => 'history']) }}">
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
    <div class="registrar-case-progress-cancel">{{ $this->getAction('cancelCase', isMounting: false) }}</div>
</div>
