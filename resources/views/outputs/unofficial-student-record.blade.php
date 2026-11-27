@php
    $student->loadMissing(['program', 'curriculumVersion.academicYears']);
    $curriculumVersion = $student->curriculumVersion;
    $batchLabels = $curriculumVersion?->academicYears
        ?->sortBy('starts_on')
        ->pluck('label')
        ->filter()
        ->implode(', ');
    $curriculumDisplay = $curriculumVersion
        ? collect([$curriculumVersion->name ?: 'Assigned curriculum', filled($batchLabels) ? 'Batch '.$batchLabels : null])->filter()->implode(' · ')
        : 'Not recorded';
@endphp
<x-official-output-layout
    title="UNOFFICIAL STUDENT RECORD"
    subtitle="Unofficial — for student reference"
    page-orientation="portrait"
    page-margin="12mm"
    context="UNOFFICIAL"
    :generated-at="$asOf->format('F j, Y g:i A')"
    :back-url="\App\Filament\Student\Pages\Academics::getUrl(panel: 'student')"
    back-label="Back to grades"
    :logo-src="asset('images/brand/servitech-crest.webp')"
>
    <section aria-labelledby="student-record-identity">
        <h2 id="student-record-identity" class="finance-heading">Student and curriculum</h2>
        <div class="finance-grid">
            <p><strong>Student number</strong><br>{{ $student->student_number }}</p>
            <p><strong>Legal name</strong><br>{{ collect([$student->last_name, $student->first_name, $student->middle_name])->filter()->implode(', ') }}</p>
            <p><strong>Program</strong><br>{{ $student->program?->name ?? $student->program?->code ?? 'Not recorded' }}</p>
            <p><strong>Curriculum</strong><br>{{ $curriculumDisplay }}</p>
        </div>
        <p class="official-output-notice">UNOFFICIAL — FOR STUDENT REFERENCE. This is not a Transcript of Records and carries no certification, signatory, or seal.</p>
    </section>

    @foreach ($recordPeriods as $period)
        <section aria-labelledby="record-term-{{ $period['id_slug'] }}">
            <h2 id="record-term-{{ $period['id_slug'] }}" class="finance-heading">{{ $period['heading'] }}</h2>
            <div class="official-output-table finance-responsive-table">
                <table>
                    <caption class="tala-sr-only">{{ $period['heading'] }} released academic results</caption>
                    <thead>
                        <tr><th colspan="4" style="background: #f4f4f5; font-weight: 700; border-bottom: 2px solid #09090b;">{{ $student->student_number }} · {{ collect([$student->last_name, $student->first_name])->filter()->implode(', ') }} · {{ $period['label'] }} · UNOFFICIAL RECORD</th></tr>
                        <tr><th scope="col">Subject</th><th scope="col">Units</th><th scope="col">Released result</th><th scope="col">Released</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($period['rows'] as $result)
                            <tr>
                                <th scope="row" data-label="Subject"><div><strong>{{ $result['course_specification']?->title }}</strong><br>{{ $result['course_specification']?->course?->code }}</div></th>
                                <td data-label="Units">{{ (float) $result['units'] }}</td>
                                <td data-label="Released result"><div>
                                    {{ $result['result'] }}
                                    @if ($result['inc_deadline'])
                                        <br>Completion deadline: {{ $result['inc_deadline']->format('M j, Y') }}
                                        <br>{{ $result['inc_state'] === \App\Actions\Grades\IncDeadlineService::StateCompletionOverdue ? 'Completion overdue — no credit.' : 'Completion open.' }}
                                    @endif
                                    @if ($result['event']?->predecessor_event_id)
                                        <br>Supersedes an earlier released result.
                                    @endif
                                </div></td>
                                <td data-label="Released">
                                    @if (($result['prior_credit'] ?? null) instanceof \App\Models\PriorAcademicCredit)
                                        {{ filled($result['prior_credit']->source_school) ? 'Prior record · '.$result['prior_credit']->source_school : 'Prior institutional record' }}
                                    @else
                                        {{ $result['event']?->released_at?->timezone(config('app.display_timezone'))->format('M j, Y') ?? 'Registrar-recorded outcome' }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @php
                $average = $period['average'];
            @endphp
            <p><strong>{{ $average['label'] }}:</strong> {{ $average['value'] ?? $average['reason'] }}</p>
        </section>
    @endforeach

    <section aria-labelledby="record-summary">
        <h2 id="record-summary" class="finance-heading">Academic progress</h2>
        <p><strong>Cumulative GWA:</strong> {{ $cumulative['value'] ?? 'Unavailable' }} @if ($cumulative['through']) · Through {{ $cumulative['through'] }} @endif</p>
        @if ($cumulative['reason'])<p>{{ $cumulative['reason'] }}</p>@endif
        <p><strong>Completed curriculum units:</strong> {{ $curriculum['completed_units'] }}</p>
        <p><strong>Remaining requirements:</strong> {{ $curriculum['deficiency_count'] }}</p>
    </section>
</x-official-output-layout>
