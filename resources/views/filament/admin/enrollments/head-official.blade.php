@assets<link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">@endassets
<div class="head-enrollment-detail">
    <div class="head-enrollment-context">
        <div><p>{{ $case->studentProfile?->program?->name ?? 'Program unavailable' }}</p><p>{{ $case->currentCorVersion?->proposalVersion?->curriculumVersion?->name ?? $case->studentProfile?->curriculumVersion?->name ?? 'Curriculum unavailable' }}</p></div>
        <div><x-filament::badge color="success">Officially enrolled</x-filament::badge><p>Read-only · Registrar manages enrollment</p></div>
    </div>
    <div class="head-enrollment-board">
        <section class="head-enrollment-panel head-enrollment-subjects" aria-labelledby="head-official-subjects">
            <div class="head-enrollment-panel-heading">
                <h2 id="head-official-subjects">Official class registrations</h2>
                <dl class="head-enrollment-figures"><div><dt>Subjects</dt><dd>{{ $subjects->count() }}</dd></div><div><dt>Units</dt><dd>{{ (float) $subjects->sum('units_snapshot') }}</dd></div></dl>
            </div>
            @if ($subjects->isNotEmpty())
                <table class="head-enrollment-subject-table"><caption class="sr-only">Current active official subjects for this enrollment</caption>
                    <thead><tr><th scope="col">Subject</th><th scope="col">Section</th><th scope="col">Units</th></tr></thead>
                    <tbody>@foreach ($subjects as $subject)
                        @php($specification = $subject->termOffering?->curriculumEntry?->courseSpecification)
                        <tr><th scope="row">{{ $subject->proposalItem?->course_title_snapshot ?? $specification?->title ?? 'Subject unavailable' }}<small>{{ $subject->proposalItem?->course_code_snapshot ?? $specification?->course?->code }}</small></th><td data-label="Section">{{ $subject->section?->code ?? 'Section unavailable' }}</td><td data-label="Units">{{ (float) $subject->units_snapshot }}</td></tr>
                    @endforeach</tbody>
                </table>
            @else<p class="head-enrollment-note">No active subjects remain. Recorded changes are available in Enrollment history and source details.</p>@endif
            @foreach ($subjects->filter(fn ($subject) => ($subject->proposalItem?->scheduling_treatment_snapshot ?? $subject->termOffering?->curriculumEntry?->courseSpecification?->scheduling_treatment) === \App\Models\CourseSpecification::SchedulingExternallyArranged) as $subject)<p class="head-enrollment-note">{{ $subject->proposalItem?->course_title_snapshot ?? $subject->termOffering?->curriculumEntry?->courseSpecification?->title ?? 'This subject' }} is externally arranged and has no recurring meeting.</p>@endforeach
        </section>
        <section class="head-enrollment-panel head-enrollment-record" aria-labelledby="head-recorded-enrollment">
            <h2 id="head-recorded-enrollment">Recorded enrollment</h2>
            <dl>
                <div><dt>Made official</dt><dd>{{ $case->officially_enrolled_at?->timezone('Asia/Manila')->format('j M Y, g:i A') ?? 'Date unavailable' }}<small>Asia/Manila</small></dd></div>
                <div><dt>Recorded by</dt><dd>{{ $case->finalizer?->getFilamentName() ?? 'Registrar not recorded' }}</dd></div>
                <div><dt>Current enrollment clearance</dt><dd><x-filament::badge :color="$requirement['state'] === 'Cleared' ? 'success' : 'gray'">{{ match ($requirement['state']) { 'Cleared' => 'Cleared', 'ActionNeeded' => 'Accounting review needed', default => 'Unavailable' } }}</x-filament::badge><small>Accounting · checked {{ \Carbon\CarbonImmutable::parse($requirement['as_of'])->timezone('Asia/Manila')->format('j M Y, g:i A') }}</small></dd></div>
            </dl>
            @if ($pendingProposal)<p class="head-enrollment-note">A subject change is being reviewed. The official registrations shown here remain current until the change is applied.</p>@endif
        </section>
        <section class="head-enrollment-panel head-enrollment-schedule" aria-labelledby="head-enrollment-schedule">
            <h2 id="head-enrollment-schedule">Published class schedule</h2>
            @if ($schedule && $schedule['meetings'] !== [])
                <p class="head-enrollment-note">Current published meetings for these official subjects · Asia/Manila</p>
                <ul class="head-enrollment-meetings">@foreach ($schedule['meetings'] as $meeting)
                    <li><div class="head-enrollment-meeting-time"><strong>{{ $meeting['dayLabel'] ?: 'Day unavailable' }}</strong><span>{{ $meeting['time'] ?: 'Time unavailable' }}</span></div><div><h3>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</h3><p>{{ $meeting['class'] }} · {{ $meeting['room'] }} · {{ $meeting['faculty'] }}</p>@if (! $meeting['plottable'] || $meeting['offGrid'])<p>Meeting time needs Registrar review.</p>@endif</div></li>
                @endforeach</ul>
            @else<p class="head-enrollment-note">{{ $schedule === null ? 'No published timetable is available for this term.' : 'No recurring meetings are recorded for these subjects. Externally arranged subjects use their recorded teaching arrangements.' }}</p>@endif
        </section>
    </div>
    <details class="head-enrollment-evidence">
        <summary>Enrollment history and source details</summary>
        <dl class="head-enrollment-source-grid">
            <div><dt>Registration reference</dt><dd>{{ $case->case_reference }}</dd></div>
            <div><dt>Academic selection basis</dt><dd>{{ filled($case->selection_basis) ? \Illuminate\Support\Str::headline($case->selection_basis) : 'Not recorded' }}</dd></div>
            <div><dt>Current certificate issued</dt><dd>{{ $case->currentCorVersion?->issued_at?->timezone('Asia/Manila')->format('j M Y, g:i A') ?? 'No issued certificate recorded' }}</dd></div>
            <div><dt>Issued subject confirmation</dt><dd>{{ $case->currentCorVersion?->proposalVersion?->confirmation ? \Illuminate\Support\Str::headline($case->currentCorVersion->proposalVersion->confirmation->method).' · '.$case->currentCorVersion->proposalVersion->confirmation->confirmed_at?->timezone('Asia/Manila')->format('j M Y, g:i A') : 'Not recorded' }}</dd></div>
            <div><dt>Clearance source</dt><dd>{{ $requirement['assessment_id'] !== null ? 'Assessment '.$requirement['assessment_id'].' · '.\Illuminate\Support\Str::headline($requirement['satisfaction_basis']) : 'Current assessment unavailable' }}</dd></div>
            <div><dt>Current timetable source</dt><dd>{{ $schedule['source'] ?? 'Unavailable' }}</dd></div>
        </dl>
        @if ($pendingProposal)
            <h3>Proposed subject change · version {{ $pendingProposal->version }}</h3>
            <p class="head-enrollment-note">{{ $pendingProposal->state }} · This proposal has not replaced the official registrations.</p>
            <ul class="head-enrollment-pending">@foreach ($pendingProposal->items as $item)<li>{{ $item->course_title_snapshot }} · {{ $item->section?->code ?? 'Section unavailable' }} · {{ (float) $item->units_snapshot }} units</li>@endforeach</ul>
        @endif
        <h3>Recorded activity</h3>
        @include('filament.admin.enrollments.head-history')
    </details>
</div>
