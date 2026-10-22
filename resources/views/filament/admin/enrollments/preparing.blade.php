@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        @if ($case->studentProfile?->student_number)<p>{{ $case->studentProfile->student_number }}</p>@endif
        <p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name ?? 'Program unavailable' }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    @if ($case->admissionApplication?->application_path === \App\Models\AdmissionApplication::PathTransferee && $evaluation === null)
        <section class="registrar-requirements registrar-evaluation-task" aria-labelledby="evaluation-heading">
            <h2 id="evaluation-heading">Record transfer credit evaluation</h2>
            <p>Use the school’s approved result before selecting subjects. Record a no-credit result when applicable.</p>
            <div class="registrar-case-task-actions">{{ $this->getAction('recordAcademicEvaluation', isMounting: false) }}</div>
            <div class="registrar-preparation-next"><h3>Next: choose subjects</h3><p>Subject selection becomes available after the evaluation is recorded.</p></div>
        </section>
    @else
    <section class="registrar-case-preparation" aria-labelledby="preparation-heading">
        <div><h2 id="preparation-heading">Prepare subjects</h2><p>{{ $case->selection_basis === \App\Models\Enrollment::SelectionIndividuallyAdvised ? 'This enrollment needs an individually advised subject selection.' : 'Prepare the complete curriculum subjects and classes for this enrollment.' }}</p></div>
        {{ $this->getAction('prepareProposal', isMounting: false) }}
    </section>
    <p class="registrar-case-meta">No subjects selected. Saving a selection prepares a draft for review.</p>
    @endif
    <a class="registrar-case-history-link" href="{{ $this->enrollmentUrl(['view' => 'history']) }}">
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
    <div class="registrar-case-progress-cancel">{{ $this->getAction('cancelCase', isMounting: false) }}</div>
</div>
