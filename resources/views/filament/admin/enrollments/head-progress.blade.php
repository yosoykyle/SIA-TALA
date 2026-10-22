@assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
<div class="head-enrollment-detail">
    <div class="head-enrollment-context">
        <div><p>{{ $proposal?->curriculumVersion?->program?->name ?? $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name ?? 'Program unavailable' }}</p>@if ($proposal)<p>{{ $proposal->curriculumVersion?->name ?? 'Curriculum unavailable' }}</p>@endif</div>
        <div><x-filament::badge color="gray">In progress</x-filament::badge><p>Read-only · Registrar manages enrollment</p></div>
    </div>
    <div class="head-enrollment-board">
        <section class="head-enrollment-panel head-enrollment-subjects" aria-labelledby="head-proposed-subjects">
            @if ($proposal && $proposal->items->isNotEmpty())
                <div class="head-enrollment-panel-heading"><h2 id="head-proposed-subjects">{{ match ($proposal->state) { \App\Models\RegistrationProposalVersion::StateDraft => 'Draft subjects', \App\Models\RegistrationProposalVersion::StateConfirmed => 'Confirmed proposed subjects', default => 'Proposed subjects' } }}</h2><dl class="head-enrollment-figures"><div><dt>Subjects</dt><dd>{{ $proposal->items->count() }}</dd></div><div><dt>Units</dt><dd>{{ (float) $proposal->items->sum('units_snapshot') }}</dd></div></dl></div>
                <p class="head-enrollment-note">{{ $proposal->state === \App\Models\RegistrationProposalVersion::StateDraft ? 'Draft subjects recorded by Registrar.' : 'These subjects are proposed and are not official registrations.' }}</p>
                <table class="head-enrollment-subject-table"><caption class="sr-only">Current proposed subjects, not official registrations</caption><thead><tr><th scope="col">Subject</th><th scope="col">Section</th><th scope="col">Units</th></tr></thead><tbody>
                    @foreach ($proposal->items as $item)<tr><th scope="row">{{ $item->course_title_snapshot }}<small>{{ $item->course_code_snapshot }}</small></th><td data-label="Section">{{ $item->section?->code ?? 'Section unavailable' }}</td><td data-label="Units">{{ (float) $item->units_snapshot }}</td></tr>@endforeach
                </tbody></table>
                @foreach ($proposal->items->where('scheduling_treatment_snapshot', \App\Models\CourseSpecification::SchedulingExternallyArranged) as $item)<p class="head-enrollment-note">{{ $item->course_title_snapshot }} is externally arranged and has no recurring meeting.</p>@endforeach
            @else
                <div class="head-enrollment-empty"><x-heroicon-o-document-text aria-hidden="true" /><h2 id="head-proposed-subjects">Subjects being prepared</h2><p>Registrar has not recorded a subject proposal for this enrollment.</p></div>
            @endif
        </section>
        <section class="head-enrollment-panel head-enrollment-record" aria-labelledby="head-enrollment-progress">
            <h2 id="head-enrollment-progress">{{ $proposal ? $progress : 'Enrollment progress' }}</h2>
            <dl>
                <div><dt>Responsible</dt><dd>{{ $owner }}</dd></div>
                <div><dt>Subject proposal</dt><dd>{{ $proposal ? 'Version '.$proposal->version.' · '.$proposal->state : 'Not recorded' }}</dd></div>
                @if ($deadline)<div><dt>Recorded seat deadline</dt><dd>{{ $deadline->timezone('Asia/Manila')->format('j M Y, g:i A') }}<small>Asia/Manila{{ $deadline->isPast() ? ' · Deadline passed; Registrar review required' : '' }}</small></dd></div>@endif
                @if ($proposal)<div><dt>Current enrollment clearance</dt><dd>{{ match ($requirement['state']) { 'Cleared' => 'Cleared', 'ActionNeeded' => 'Accounting review needed', default => 'Unavailable' } }}<small>Accounting</small></dd></div>@endif
                <div><dt>Enrollment started</dt><dd>{{ $case->started_at?->timezone('Asia/Manila')->format('j M Y, g:i A') ?? 'Date unavailable' }}</dd></div>
            </dl>
        </section>
        @if ($proposal && $schedule && $schedule['meetings'] !== [])
            <section class="head-enrollment-panel head-enrollment-schedule" aria-labelledby="head-proposed-meetings">
                <h2 id="head-proposed-meetings">{{ $proposal->state === \App\Models\RegistrationProposalVersion::StateDraft ? 'Draft class schedule' : 'Proposed class schedule' }}</h2><p class="head-enrollment-note">{{ $schedule['source'] }} · {{ $placementCurrent ? 'Current placement source' : 'Placement needs Registrar review' }} · Asia/Manila</p>
                <ul class="head-enrollment-meetings">@foreach ($schedule['meetings'] as $meeting)<li><div class="head-enrollment-meeting-time"><strong>{{ $meeting['dayLabel'] ?: 'Day unavailable' }}</strong><span>{{ $meeting['time'] ?: 'Time unavailable' }}</span></div><div><h3>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</h3><p>{{ $meeting['class'] }} · {{ $meeting['room'] }} · {{ $meeting['faculty'] }}</p>@if (! $meeting['plottable'] || $meeting['offGrid'])<p>Meeting time needs Registrar review.</p>@endif</div></li>@endforeach</ul>
            </section>
        @endif
    </div>
    <details class="head-enrollment-evidence"><summary>Enrollment history and source details</summary>
        <dl class="head-enrollment-source-grid">
            <div><dt>Registration reference</dt><dd>{{ $case->case_reference }}</dd></div>
            <div><dt>Academic selection basis</dt><dd>{{ filled($case->selection_basis) ? \Illuminate\Support\Str::headline($case->selection_basis) : 'Not recorded' }}</dd></div>
            <div><dt>Subject confirmation</dt><dd>{{ $proposal?->confirmation ? \Illuminate\Support\Str::headline($proposal->confirmation->method).' · '.$proposal->confirmation->confirmed_at?->timezone('Asia/Manila')->format('j M Y, g:i A') : 'Not recorded' }}</dd></div>
            @if ($proposal)<div><dt>Verified identity</dt><dd>{{ $readiness['identity'] ? 'Current' : 'Registrar review needed' }}</dd></div><div><dt>Academic eligibility</dt><dd>{{ $readiness['eligibility'] ? 'Current' : 'Registrar review needed' }}</dd></div><div><dt>Class placement</dt><dd>{{ $placementCurrent ? 'Current source' : 'Registrar review needed' }}</dd></div>@endif
        </dl>
        @if ($readiness['shortages'])<h3>Class capacity concerns</h3><ul class="head-enrollment-pending">@foreach ($readiness['shortages'] as $shortage)<li>{{ $shortage['course'] }} · {{ $shortage['section'] }} · No available seats · {{ $shortage['owner'] }}</li>@endforeach</ul>@endif
        <h3>Recorded activity</h3>
        @include('filament.admin.enrollments.head-history')
    </details>
</div>
