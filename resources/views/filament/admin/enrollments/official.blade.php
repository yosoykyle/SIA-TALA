@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        <p>{{ $case->studentProfile?->student_number ?? 'Student number unavailable' }}</p>
        <p>{{ $case->studentProfile?->program?->name ?? 'Program unavailable' }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <section class="registrar-case-official" aria-labelledby="official-heading">
        <div>
            <h2 id="official-heading"><x-filament::icon icon="heroicon-o-check-badge" />Officially enrolled</h2>
            <p>{{ $case->officially_enrolled_at ? 'Enrolled on '.$case->officially_enrolled_at->timezone(config('app.display_timezone'))->format('M j, Y').'.' : 'Enrollment date not recorded.' }}</p>
        </div>
        <div class="registrar-case-certificate">
            <x-filament::button icon="heroicon-o-document-text" tag="a" :href="route('cor.print', $case)" target="_blank" rel="noopener">View registration certificate</x-filament::button>
            <p>Issued {{ $case->currentCorVersion->created_at?->timezone(config('app.display_timezone'))->format('M j, Y') ?? 'date unavailable' }}</p>
        </div>
    </section>
    <section class="registrar-case-current-subjects" aria-labelledby="subjects-heading">
        <div class="registrar-subject-heading">
            <h2 id="subjects-heading">Subjects</h2>
            {{ ($this->getAction('prepareAdjustmentProposal', isMounting: false)->label('Add a subject')->color('primary'))(['change_type' => 'Add']) }}
        </div>
        <p class="registrar-case-meta">{{ $subjects->count() }} {{ \Illuminate\Support\Str::plural('subject', $subjects->count()) }} · {{ (float) $subjects->sum('units_snapshot') }} units</p>
        @if ($subjects->isNotEmpty())
            <table class="registrar-subject-table" role="table">
                <caption class="sr-only">Current official subjects</caption>
                <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Subject</th><th scope="col" role="columnheader">Section</th><th scope="col" role="columnheader">Units</th><th scope="col" role="columnheader"><span class="sr-only">Actions</span></th></tr></thead>
                <tbody role="rowgroup">
                    @foreach ($subjects as $subject)
                        @php($specification = $subject->termOffering?->curriculumEntry?->courseSpecification)
                        <tr wire:key="official-subject-{{ $subject->id }}" role="row">
                            <th scope="row" role="rowheader">{{ $subject->proposalItem?->course_title_snapshot ?? $specification?->title ?? 'Subject title unavailable' }}<span>{{ $subject->proposalItem?->course_code_snapshot ?? $specification?->course?->code }}</span></th>
                            <td role="cell"><span class="registrar-subject-mobile-label">Section</span>{{ $subject->section?->code ?? 'Section unavailable' }}</td>
                            <td role="cell"><span class="registrar-subject-mobile-label">Units</span>{{ (float) $subject->units_snapshot }}</td>
                            <td class="registrar-subject-actions" role="cell">
                                {{ ($this->getAction('prepareAdjustmentProposal', isMounting: false)->label('Change class')->color('gray'))(['change_type' => 'Replace', 'current_course_enrollment_id' => $subject->id]) }}
                                {{ ($this->getAction('dropCourse', isMounting: false))(['course_enrollment_id' => $subject->id]) }}
                            </td>
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
