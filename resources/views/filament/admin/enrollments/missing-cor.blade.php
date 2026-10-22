@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        <p>{{ $case->studentProfile?->student_number ?? 'Student number unavailable' }}</p>
        <p>{{ $case->studentProfile?->program?->name ?? 'Program unavailable' }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
<section class="accounting-proof-task"><div><h2>Registration certificate source unavailable</h2><p>Official enrollment is recorded; current certificate source is missing.</p></div></section><section class="registrar-case-reference"><h2>Investigate the recorded source</h2><p>Registrar and the system administrator must investigate the missing current certificate source. Live subjects will not be substituted into a new certificate.</p><p>Existing official subjects and retained certificate versions remain recorded.</p>@foreach ($case->corVersions()->orderByDesc('version')->get() as $version)<p><x-filament::link :href="route('cor.print', ['enrollment'=>$case,'version'=>$version->version])" target="_blank" rel="noopener">View retained certificate &middot; version {{ $version->version }}</x-filament::link></p>@endforeach</section>    <section class="registrar-case-current-subjects" aria-labelledby="subjects-heading">
        <h2 id="subjects-heading">Subjects</h2>
        <p class="registrar-case-meta">{{ $subjects->count() }} {{ \Illuminate\Support\Str::plural('subject', $subjects->count()) }} · {{ (float) $subjects->sum('units_snapshot') }} units</p>
        @if ($subjects->isNotEmpty())
            <table class="registrar-subject-table">
                <caption class="sr-only">Current official subjects</caption>
                <thead><tr><th scope="col">Subject</th><th scope="col">Section</th><th scope="col">Units</th></tr></thead>
                <tbody>
                    @foreach ($subjects as $subject)
                        @php($specification = $subject->termOffering?->curriculumEntry?->courseSpecification)
                        <tr wire:key="official-subject-{{ $subject->id }}">
                            <th scope="row">{{ $subject->proposalItem?->course_title_snapshot ?? $specification?->title ?? 'Subject title unavailable' }}<span>{{ $subject->proposalItem?->course_code_snapshot ?? $specification?->course?->code }}</span></th>
                            <td>{{ $subject->section?->code ?? 'Section unavailable' }}</td>
                            <td>{{ (float) $subject->units_snapshot }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <p class="registrar-case-meta">No active subjects remain in this enrollment. Review Enrollment history for recorded changes.</p>
        @endif
        @foreach ($subjects->filter(fn ($subject) => ($subject->proposalItem?->scheduling_treatment_snapshot ?? $subject->termOffering?->curriculumEntry?->courseSpecification?->scheduling_treatment) === \App\Models\CourseSpecification::SchedulingExternallyArranged) as $subject)
            <p class="registrar-case-meta">{{ $subject->proposalItem?->course_code_snapshot ?? $subject->termOffering?->curriculumEntry?->courseSpecification?->course?->code }} is externally arranged and has no recurring meeting.</p>
        @endforeach
    </section>
    <section class="registrar-case-schedule" aria-labelledby="schedule-heading">
        <h2 id="schedule-heading">Schedule</h2>
        @if ($schedule && $schedule['meetings'] !== [])
            <x-enrollment-schedule :presentation="$schedule" :source-key="'registrar-official-'.$case->id" />
        @else
            <p class="registrar-case-meta">{{ $schedule === null ? 'No published timetable is available for this term.' : 'No recurring meetings are recorded for these subjects.' }}</p>
        @endif
    </section>
    <a class="registrar-case-history-link" href="{{ \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history']) }}">
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
</div>
