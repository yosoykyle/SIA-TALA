@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        @if ($case->studentProfile?->student_number)<p>{{ $case->studentProfile->student_number }}</p>@endif
        <p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name ?? 'Program unavailable' }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    @if ($case->admissionApplication?->application_path === \App\Models\AdmissionApplication::PathTransferee && $evaluation === null)
        <section class="registrar-requirements registrar-evaluation-task tala-task-surface" aria-labelledby="evaluation-heading">
            <h2 id="evaluation-heading">Record transfer credit evaluation</h2>
            <p>Use the school’s approved result before selecting subjects. Record a no-credit result when applicable.</p>
            <div class="registrar-case-task-actions">{{ $this->getAction('recordAcademicEvaluation', isMounting: false) }}</div>
            <div class="registrar-preparation-next"><h3>Next: choose subjects</h3><p>Subject selection becomes available after the evaluation is recorded.</p></div>
        </section>
    @else
    @php
        $irregularRequest = \App\Models\IrregularEnrollmentRequest::query()->where('enrollment_id', $case->id)->first();
        $irregularReview ??= $case->studentProfile !== null
            ? app(\App\Actions\Academics\IrregularStudentReasons::class)->forStudent($case->studentProfile, $case->term)
            : null;
    @endphp
    @if ($irregularRequest !== null)
        <section class="registrar-irregular-request tala-task-surface" aria-labelledby="irregular-request-heading">
            <h2 id="irregular-request-heading">Irregular application · {{ \App\Models\IrregularEnrollmentRequest::stateLabels()[$irregularRequest->state] ?? $irregularRequest->state }}</h2>
            <p class="registrar-case-meta">Submitted {{ $irregularRequest->submitted_at?->timezone(config('app.display_timezone'))->format('M j, Y g:i A') }} · {{ count($irregularRequest->sectionIds()) }} {{ str('class')->plural(count($irregularRequest->sectionIds())) }} · {{ rtrim(rtrim((string) $irregularRequest->selected_units, '0'), '.') }} units</p>
            <x-irregular-reasons :review="['is_irregular' => true, 'kept_regular' => false, 'decision_reason' => null, 'status_label' => 'Irregular'.(($irregularRequest->reasons[0]['label'] ?? null) ? ' – '.$irregularRequest->reasons[0]['label'] : ''), 'reasons' => $irregularRequest->reasons ?? []]" />
            @if (filled($irregularRequest->student_remarks))<p><strong>Student remarks:</strong> {{ $irregularRequest->student_remarks }}</p>@endif
            @if (($irregularRequest->supporting_documents ?? []) !== [])<p><strong>Supporting documents:</strong> {{ count($irregularRequest->supporting_documents) }} attached</p>{{ $this->getAction('downloadIrregularDocuments', isMounting: false) }}@endif
            @if ($irregularRequest->state === \App\Models\IrregularEnrollmentRequest::StateReturned)<p><strong>Returned:</strong> {{ $irregularRequest->return_reason }}</p>@endif
            @if ($irregularRequest->state === \App\Models\IrregularEnrollmentRequest::StateSubmitted)
                <p>Choose subjects opens with the student’s requested classes ticked. Saving them approves the application.</p>
                <div class="registrar-case-task-actions">{{ $this->getAction('returnIrregularRequest', isMounting: false) }}</div>
            @endif
        </section>
    @elseif (($irregularReview['is_irregular'] ?? false) === true)
        <section class="registrar-irregular-request tala-task-surface" aria-labelledby="irregular-status-heading">
            <h2 id="irregular-status-heading">Irregular status</h2>
            <x-irregular-reasons :review="$irregularReview" />
        </section>
    @endif
    <section class="registrar-case-preparation tala-task-surface" aria-labelledby="preparation-heading">
        <div><h2 id="preparation-heading">Prepare subjects</h2><p>{{ $case->selection_basis === \App\Models\Enrollment::SelectionIndividuallyAdvised ? 'This enrollment needs an individually advised subject selection.' : 'Prepare the complete curriculum subjects and classes for this enrollment.' }}</p></div>
        {{ $this->getAction('prepareProposal', isMounting: false) }}
    </section>
    <p class="registrar-case-meta">No subjects selected. Saving a selection prepares a draft for review.</p>
    @endif
    <a class="registrar-case-history-link" {{ \Filament\Support\generate_href_html($this->enrollmentUrl(['view' => 'history'])) }}>
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
    <div class="registrar-case-progress-cancel">{{ $this->getAction('cancelCase', isMounting: false) }}</div>
</div>
