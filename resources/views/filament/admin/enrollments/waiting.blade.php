@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php
    $money = fn ($amount) => $amount === null ? 'Unavailable' : 'PHP '.number_format((float) $amount, 2);
    $deadline = $proposal->items->map(fn ($item) => $item->reservation?->deadline)->filter()->sort()->first();
@endphp
<div class="registrar-case">
    <div class="registrar-case-identity">
        <p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }}</p>
        <p>{{ $proposal->curriculumVersion?->program?->name ?? $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name ?? 'Program unavailable' }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <section class="registrar-case-waiting" aria-labelledby="waiting-heading">
        <div><h2 id="waiting-heading">Waiting for confirmation</h2><p>The student can review these subjects in Enrollment.</p></div>
        <div class="registrar-case-confirm">{{ $this->getAction('recordAssistedConfirmation', isMounting: false) }}<p>Use this when the student confirms with your office.</p></div>
        @if ($deadline)
            <p class="registrar-case-deadline">Seats held until <strong>{{ \Carbon\CarbonImmutable::parse($deadline)->timezone(config('app.display_timezone'))->format('M j, Y · g:i A') }}</strong></p>
        @endif
    </section>
    <div class="registrar-case-grid">
        <section class="registrar-case-subjects" aria-labelledby="subjects-heading">
            <h2 id="subjects-heading">Subjects</h2>
            <p class="registrar-case-meta">{{ $proposal->items->count() }} subjects · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'waiting'])
            @foreach ($proposal->items->where('scheduling_treatment_snapshot', \App\Models\CourseSpecification::SchedulingExternallyArranged) as $item)
                <p class="registrar-case-meta">{{ $item->course_code_snapshot }} is externally arranged and has no recurring meeting.</p>
            @endforeach
        </section>
        <section class="registrar-case-payment" aria-labelledby="payment-heading">
            <h2 id="payment-heading">Enrollment payment</h2>
            @if ($requirement['state'] === 'Cleared')
                <p class="registrar-case-amount">Cleared</p><p>The enrollment payment requirement is satisfied.</p>
            @else
                <p class="registrar-case-amount">{{ $money($requirement['balance']) }}</p><p>Still needed</p>
            @endif
            <dl><div><dt>Total charges</dt><dd>{{ $money($assessment?->total) }}</dd></div></dl>
            <p class="registrar-case-meta">Accounting reviews payments and approved coverage.</p>
            @if ((float) $requirement['payment_applied'] > 0 || (float) $requirement['coverage_applied'] > 0)
                <details class="registrar-case-reference"><summary>Payment breakdown</summary><dl>
                    <div><dt>Required for enrollment</dt><dd>{{ $money($requirement['total']) }}</dd></div>
                    @if ((float) $requirement['payment_applied'] > 0)<div><dt>Verified payment applied</dt><dd>{{ $money($requirement['payment_applied']) }}</dd></div>@endif
                    @if ((float) $requirement['coverage_applied'] > 0)<div><dt>Approved coverage applied</dt><dd>{{ $money($requirement['coverage_applied']) }}</dd></div>@endif
                </dl></details>
            @endif
        </section>
    </div>
    <section class="registrar-case-schedule" aria-labelledby="schedule-heading">
        <h2 id="schedule-heading">Schedule</h2>
        @if ($schedule && $schedule['meetings'] !== [])
            <x-enrollment-schedule :presentation="$schedule" :source-key="'registrar-waiting-'.$proposal->id" />
        @else
            <p class="registrar-case-meta">No recurring meetings are recorded for these subjects.</p>
        @endif
    </section>
    <a class="registrar-case-history-link" href="{{ \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history']) }}">
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
</div>
