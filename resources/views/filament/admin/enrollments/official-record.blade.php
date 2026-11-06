@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php($certificateAvailable = $case->currentCorVersion !== null)
<div class="registrar-case tala-enrollment-record">
    @include('filament.admin.enrollments.record-header')
    <section class="tala-enrollment-ledger tala-task-surface" aria-labelledby="subjects-heading">
        <header class="tala-enrollment-ledger-heading">
            <div>
                <h2 id="subjects-heading">{{ $subjects->count() }} registered {{ \Illuminate\Support\Str::plural('subject', $subjects->count()) }} · {{ (float) $subjects->sum('units_snapshot') }} units</h2>
                <x-filament::badge color="success" icon="heroicon-o-check-badge">Officially enrolled</x-filament::badge>
                <p class="registrar-case-meta">{{ $case->officially_enrolled_at ? 'Enrolled on '.$case->officially_enrolled_at->timezone(config('app.display_timezone'))->format('M j, Y').'.' : 'Enrollment date not recorded.' }}</p>
            </div>
            @if ($certificateAvailable)
                <div class="tala-enrollment-ledger-actions">
                    <x-filament::button icon="heroicon-o-document-text" tag="a" :href="route('cor.print', $case)" target="_blank" rel="noopener">View registration certificate</x-filament::button>
                    {{ ($this->getAction('prepareAdjustmentProposal', isMounting: false)->label('Add a subject')->color('gray'))(['change_type' => 'Add']) }}
                </div>
            @endif
        </header>
        @if (! $certificateAvailable)
            <div class="tala-enrollment-source-notice" role="note">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" />
                <div><h3>Registration certificate source unavailable</h3><p>Registrar and the system administrator must review the recorded source. Earlier copies appear in History when available.</p></div>
            </div>
        @endif
        @if ($subjects->isNotEmpty())
            <div class="tala-enrollment-subjects registrar-case-current-subjects">
                <table class="registrar-subject-table" role="table">
                    <caption class="sr-only">Current official subjects</caption>
                    <thead role="rowgroup"><tr role="row"><th scope="col" role="columnheader">Subject</th><th scope="col" role="columnheader">Class</th><th scope="col" role="columnheader">Units</th>@if ($certificateAvailable)<th scope="col" role="columnheader"><span class="sr-only">Actions</span></th>@endif</tr></thead>
                    <tbody role="rowgroup">
                        @foreach ($subjects as $subject)
                            @php($specification = $subject->termOffering?->curriculumEntry?->courseSpecification)
                            <tr wire:key="official-subject-{{ $subject->id }}" role="row">
                                <th scope="row" role="rowheader">{{ $subject->proposalItem?->course_code_snapshot ?? $specification?->course?->code ?? 'Code unavailable' }}<span>{{ $subject->proposalItem?->course_title_snapshot ?? $specification?->title ?? 'Subject title unavailable' }}</span></th>
                                <td role="cell"><span class="registrar-subject-mobile-label">Class</span>{{ $subject->section?->code ?? 'Class unavailable' }}</td>
                                <td role="cell"><span class="registrar-subject-mobile-label">Units</span>{{ (float) $subject->units_snapshot }}</td>
                                @if ($certificateAvailable)
                                    <td class="registrar-subject-actions" role="cell">
                                        <div class="tala-enrollment-row-actions">
                                            {{ ($this->getAction('prepareAdjustmentProposal', isMounting: false)->label('Change class')->color('gray')->icon('heroicon-o-arrows-right-left')->size('sm'))(['change_type' => 'Replace', 'current_course_enrollment_id' => $subject->id]) }}
                                            {{ ($this->getAction('dropCourse', isMounting: false)->label('Drop subject')->outlined()->size('sm'))(['course_enrollment_id' => $subject->id]) }}
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-filament::empty-state icon="heroicon-o-book-open" class="tala-enrollment-empty">
                <x-slot:heading>No active subjects</x-slot:heading>
                <x-slot:description>No active subjects remain in this enrollment. Review History for recorded changes.</x-slot:description>
            </x-filament::empty-state>
        @endif
        @foreach ($subjects->filter(fn ($subject) => ($subject->proposalItem?->scheduling_treatment_snapshot ?? $subject->termOffering?->curriculumEntry?->courseSpecification?->scheduling_treatment) === \App\Models\CourseSpecification::SchedulingExternallyArranged) as $subject)
            <p class="tala-enrollment-ledger-note">{{ $subject->proposalItem?->course_code_snapshot ?? $subject->termOffering?->curriculumEntry?->courseSpecification?->course?->code }} is externally arranged and has no recurring meeting.</p>
        @endforeach
    </section>
    <section class="tala-enrollment-schedule tala-task-surface" aria-labelledby="schedule-heading">
        <header><x-filament::icon icon="heroicon-o-calendar-days" /><h2 id="schedule-heading">Schedule</h2></header>
        @if ($schedule && $schedule['meetings'] !== [])
            <x-enrollment-schedule :presentation="$schedule" :source-key="'registrar-official-'.$case->id" />
        @else
            <p class="registrar-case-meta">{{ $schedule === null ? 'No published timetable is available for this term.' : 'No recurring meetings are recorded for these subjects.' }}</p>
        @endif
    </section>
</div>
