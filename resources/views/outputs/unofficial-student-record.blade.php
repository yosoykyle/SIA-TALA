<x-official-output-layout
    title="UNOFFICIAL STUDENT RECORD"
    subtitle="Unofficial — for student reference"
    page-orientation="portrait"
    page-margin="12mm"
    context="UNOFFICIAL"
    :generated-at="$asOf->format('F j, Y g:i A').' Asia/Manila'"
    :logo-src="asset('talalogo.png')"
>
    <section aria-labelledby="student-record-identity">
        <h2 id="student-record-identity" class="finance-heading">Student and curriculum</h2>
        <div class="finance-grid">
            <p><strong>Student number</strong><br>{{ $student->student_number }}</p>
            <p><strong>Legal name</strong><br>{{ collect([$student->last_name, $student->first_name, $student->middle_name])->filter()->implode(', ') }}</p>
            <p><strong>Program</strong><br>{{ $student->program?->code ?? 'Not recorded' }}</p>
            <p><strong>Curriculum Version</strong><br>{{ $student->curriculumVersion?->version_code ?? 'Not recorded' }}</p>
        </div>
        <p class="official-output-notice">UNOFFICIAL — FOR STUDENT REFERENCE. This is not a Transcript of Records and carries no certification, signatory, or seal.</p>
    </section>

    @foreach ($terms as $term)
        <section aria-labelledby="record-term-{{ $term->id }}">
            <h2 id="record-term-{{ $term->id }}" class="finance-heading">{{ $term->label }}</h2>
            <div class="official-output-table finance-responsive-table">
                <table>
                    <thead>
                        <tr><th colspan="5">{{ $student->student_number }} · {{ $term->label }} · Released academic evidence</th></tr>
                        <tr><th scope="col">Course</th><th scope="col">Title</th><th scope="col">Units</th><th scope="col">Released result</th><th scope="col">Released</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($results->where('term.id', $term->id) as $result)
                            <tr>
                                <td data-label="Course">{{ $result['course_specification']?->course?->code }}</td>
                                <td data-label="Title">{{ $result['course_specification']?->title }}</td>
                                <td data-label="Units">{{ number_format((float) $result['units'], 2) }}</td>
                                <td data-label="Released result">
                                    {{ $result['result'] }}
                                    @if ($result['result'] === 'INC' && $result['event']?->deadline)
                                        <br>Completion deadline: {{ $result['event']->deadline->format('M j, Y') }}
                                    @endif
                                    @if ($result['event']?->predecessor_event_id)
                                        <br>Supersedes an earlier released result.
                                    @endif
                                </td>
                                <td data-label="Released">{{ $result['event']?->released_at?->format('M j, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @php($average = $termAverages->get($term->id))
            <p><strong>{{ $average['label'] }}:</strong> {{ $average['value'] ?? $average['reason'] }}</p>
        </section>
    @endforeach

    <section aria-labelledby="record-summary">
        <h2 id="record-summary" class="finance-heading">Curriculum and enrollment guidance</h2>
        <p><strong>Cumulative GWA:</strong> {{ $cumulative['value'] ?? 'Unavailable' }} @if ($cumulative['through']) · Through {{ $cumulative['through'] }} @endif</p>
        <p><strong>Completed curriculum units:</strong> {{ $curriculum['completed_units'] }}</p>
        <p><strong>Remaining requirements:</strong> {{ $curriculum['deficiency_count'] }}</p>
        <p><strong>Current enrollment guidance:</strong> {{ str($academicEffect['effect'])->headline() }}</p>
        <p>{{ $academicEffect['reason'] }} Source: {{ $academicEffect['source'] }}.</p>
    </section>
</x-official-output-layout>
