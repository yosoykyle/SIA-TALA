@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        @if ($case->studentProfile?->student_number)<p>{{ $case->studentProfile->student_number }}</p>@endif
        <p>{{ $proposal->curriculumVersion?->program?->name }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <section class="registrar-case-preparation" aria-labelledby="ready-heading">
        <div><h2 id="ready-heading">Ready to enroll</h2><p>Subjects confirmed. Seats reserved. Enrollment payment cleared.</p></div>
        {{ $this->getAction('finalizeOfficialEnrollment', isMounting: false) }}
    </section>
    <div class="registrar-case-grid">
        <section aria-labelledby="ready-subjects-heading">
            <h2 id="ready-subjects-heading">Subjects for enrollment</h2>
            <p class="registrar-case-meta">{{ $proposal->items->count() }} subjects · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'ready'])
        </section>
        <section class="registrar-case-payment" aria-labelledby="ready-payment-heading">
            <h2 id="ready-payment-heading">Enrollment payment</h2>
            <p class="registrar-case-amount">Cleared</p>
            <p>The enrollment requirement is satisfied. Other term fees may remain.</p>
            <dl>
                <div><dt>Required for enrollment</dt><dd>PHP {{ number_format((float) $requirement['total'], 2) }}</dd></div>
                @if ((float) $requirement['payment_applied'] > 0)<div><dt>Verified payment applied</dt><dd>PHP {{ number_format((float) $requirement['payment_applied'], 2) }}</dd></div>@endif
                @if ((float) $requirement['coverage_applied'] > 0)<div><dt>Approved coverage applied</dt><dd>PHP {{ number_format((float) $requirement['coverage_applied'], 2) }}</dd></div>@endif
                <div><dt>Total charges</dt><dd>PHP {{ number_format((float) $assessment?->total, 2) }}</dd></div>
            </dl>
        </section>
    </div>
    <section class="registrar-case-schedule" aria-labelledby="ready-schedule-heading">
        <h2 id="ready-schedule-heading">Schedule</h2>
        @if ($schedule && $schedule['meetings'] !== [])
            <x-enrollment-schedule :presentation="$schedule" :source-key="'registrar-ready-'.$proposal->id" />
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
