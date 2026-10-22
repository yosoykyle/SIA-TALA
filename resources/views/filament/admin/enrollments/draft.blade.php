@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        @if ($case->studentProfile?->student_number)<p>{{ $case->studentProfile->student_number }}</p>@endif
        <p>{{ $proposal->curriculumVersion?->program?->name }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <section class="registrar-case-preparation" aria-labelledby="draft-heading">
        <div>
            <h2 id="draft-heading">Review subjects</h2>
            <p>{{ ! $placementCurrent ? 'Reserve seats before sending these subjects to the student.' : ($requirement['state'] === 'Unavailable' ? 'Seats are reserved. Accounting must record the assessment before the student can confirm.' : 'Seats and assessment are ready. Send these subjects to the student for confirmation.') }}</p>
        </div>
        <div class="registrar-case-task-actions">
            @if (! $placementCurrent)
                {{ $this->getAction('placeProposal', isMounting: false) }}
            @elseif ($requirement['state'] !== 'Unavailable')
                {{ $this->getAction('issueProposal', isMounting: false) }}
            @endif
            {{ $this->getAction('prepareProposal', isMounting: false) }}
        </div>
    </section>
    <div class="registrar-case-grid">
        <section aria-labelledby="draft-subjects-heading">
            <h2 id="draft-subjects-heading">Subjects</h2>
            <p class="registrar-case-meta">{{ $proposal->items->count() }} subjects · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'draft'])
        </section>
        <section class="registrar-case-payment" aria-labelledby="draft-accounting-heading">
            <h2 id="draft-accounting-heading">{{ $assessment === null ? 'Accounting status' : 'Enrollment payment' }}</h2>
            @if ($requirement['state'] === 'Unavailable')
                <h3 class="registrar-case-status">Assessment needed</h3>
                <p>Accounting records the charges for this selection.</p>
            @else
                <p class="registrar-case-amount">{{ $requirement['state'] === 'Cleared' ? 'Cleared' : 'PHP '.number_format((float) $requirement['balance'], 2) }}</p>
                <p>{{ $requirement['state'] === 'Cleared' ? 'The enrollment payment requirement is satisfied.' : 'Needed for enrollment' }}</p>
                <dl><div><dt>Total charges</dt><dd>PHP {{ number_format((float) $assessment?->total, 2) }}</dd></div></dl>
            @endif
        </section>
    </div>
    <section class="registrar-case-schedule" aria-labelledby="draft-schedule-heading">
        <h2 id="draft-schedule-heading">Schedule</h2>
        @if ($schedule && $schedule['meetings'] !== [])
            <x-enrollment-schedule :presentation="$schedule" :source-key="'registrar-draft-'.$proposal->id" />
        @else
            <p class="registrar-case-meta">These subjects have no recurring meetings. Check the arranged schedule with the responsible office.</p>
        @endif
    </section>
    <a class="registrar-case-history-link" href="{{ \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history']) }}">
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
</div>
