<x-official-output-layout
    title="UNOFFICIAL STUDENT RECORD"
    subtitle="Unofficial — for student reference"
    page-orientation="portrait"
    page-margin="12mm"
    context="UNOFFICIAL"
    :generated-at="$asOf->format('F j, Y g:i A').' Asia/Manila'"
    :back-url="\App\Filament\Student\Pages\Academics::getUrl(['view' => 'record'], panel: 'student')"
    back-label="Back to academic record"
    :logo-src="asset('images/brand/servitech-crest.webp')"
>
    <section aria-labelledby="student-record-identity">
        <h2 id="student-record-identity" class="finance-heading">Student and curriculum</h2>
        <div class="finance-grid">
            <p><strong>Student number</strong><br>{{ $student->student_number }}</p>
            <p><strong>Legal name</strong><br>{{ collect([$student->last_name, $student->first_name, $student->middle_name])->filter()->implode(', ') }}</p>
            <p><strong>Program</strong><br>{{ $student->program?->name ?? $student->program?->code ?? 'Not recorded' }}</p>
            <p><strong>Curriculum</strong><br>{{ $student->curriculumVersion?->version_code ?? 'Not recorded' }}</p>
        </div>
        <p class="official-output-notice">UNOFFICIAL — FOR STUDENT REFERENCE. This is not a Transcript of Records and carries no certification, signatory, or seal.</p>
    </section>

    @foreach ($terms as $term)
        <section aria-labelledby="record-term-{{ $term->id }}">
            <h2 id="record-term-{{ $term->id }}" class="finance-heading">{{ $term->label }}@if ($term->academicYear?->label && ! str_contains($term->label, $term->academicYear->label)) · {{ $term->academicYear->label }}@endif</h2>
            <div class="official-output-table finance-responsive-table">
                <table>
                    <thead>
                        <tr><th colspan="4" style="background: #f3f4f6; font-weight: 700; border-bottom: 2px solid #111827;">{{ $student->student_number }} · {{ collect([$student->last_name, $student->first_name])->filter()->implode(', ') }} · {{ $term->label }} · UNOFFICIAL RECORD</th></tr>
                        <tr><th scope="col">Subject</th><th scope="col">Units</th><th scope="col">Released result</th><th scope="col">Released</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($results->where('term.id', $term->id) as $result)
                            <tr>
                                <td data-label="Subject"><div><strong>{{ $result['course_specification']?->title }}</strong><br>{{ $result['course_specification']?->course?->code }}</div></td>
                                <td data-label="Units">{{ (float) $result['units'] }}</td>
                                <td data-label="Released result"><div>
                                    {{ $result['result'] }}
                                    @if ($result['result'] === 'INC' && $result['event']?->deadline)
                                        @php($deadline = app(\App\Actions\Grades\IncDeadlineService::class)->currentDeadline($result['event']))
                                        <br>Completion deadline: {{ $deadline?->format('M j, Y') ?? 'Not recorded' }}
                                    @endif
                                    @if ($result['event']?->predecessor_event_id)
                                        <br>Supersedes an earlier released result.
                                    @endif
                                </div></td>
                                <td data-label="Released">{{ $result['event']?->released_at?->timezone(config('app.display_timezone'))->format('M j, Y') }}</td>
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
        <h2 id="record-summary" class="finance-heading">Academic progress</h2>
        <p><strong>Cumulative GWA:</strong> {{ $cumulative['value'] ?? 'Unavailable' }} @if ($cumulative['through']) · Through {{ $cumulative['through'] }} @endif</p>
        <p><strong>Completed curriculum units:</strong> {{ $curriculum['completed_units'] }}</p>
        <p><strong>Remaining requirements:</strong> {{ $curriculum['deficiency_count'] }}</p>
    </section>
</x-official-output-layout>
