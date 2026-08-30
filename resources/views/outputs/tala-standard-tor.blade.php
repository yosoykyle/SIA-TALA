<x-official-output-layout
    title="TRANSCRIPT OF RECORDS"
    :subtitle="$document['template_version'].' · Request '.$request->external_request_reference.' · Due '.$request->due_on->format('F j, Y')"
    :context="strtoupper($status)"
    :generated-at="$document['generated_at']"
    :logo-src="asset('talalogo.png')"
>
    <section aria-labelledby="student-identity">
        <h2 id="student-identity">Student and program</h2>
        <div class="finance-grid">
            <p><strong>Transcript reference</strong><br>{{ $document['reference'] }}</p>
            <p><strong>Generation reference</strong><br>{{ $document['generation_reference'] }}</p>
            <p><strong>Issue / preview time</strong><br>{{ $document['generated_at'] }}</p>
            <p><strong>Institution contact</strong><br>{{ $institution['phone'] ?: $institution['facebook_url'] }}</p>
            <p><strong>Legal name</strong><br>{{ $student['legal_name'] }}</p>
            <p><strong>Student number</strong><br>{{ $student['student_number'] }}</p>
            <p><strong>Program</strong><br>{{ $student['program'] }}</p>
            <p><strong>Curriculum Version</strong><br>{{ $student['curriculum_version'] }}</p>
            <p><strong>Admission basis/date</strong><br>{{ $student['admission_basis'] }} · {{ $student['admission_date'] ?? 'Date not applicable' }}</p>
            <p><strong>Prior school/credit</strong><br>{{ $student['prior_school_or_credit'] ?: 'None recorded' }}</p>
        </div>
    </section>

    @foreach ($academic_years as $academicYear => $rows)
        <section aria-labelledby="academic-year-{{ $loop->index }}">
            <h2 id="academic-year-{{ $loop->index }}" class="finance-heading">{{ $academicYear }}</h2>
            <div class="official-output-table finance-responsive-table">
                <table>
                    <thead>
                        <tr>
                            <th colspan="7">{{ $student['legal_name'] }} · {{ $student['student_number'] }} · {{ $document['reference'] }} · {{ strtoupper($status) }}</th>
                        </tr>
                        <tr><th scope="col">Term</th><th scope="col">Course</th><th scope="col">Historical title</th><th scope="col">Units</th><th scope="col">Grade/mark</th><th scope="col">Attempt / credit</th><th scope="col">Remarks</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td data-label="Term">{{ $row['term'] }}</td>
                                <td data-label="Course">{{ $row['course_code'] }}</td>
                                <td data-label="Historical title">{{ $row['course_title'] }}</td>
                                <td data-label="Units">{{ number_format((float) $row['units'], 2) }}</td>
                                <td data-label="Grade/mark">{{ $row['result'] }}</td>
                                <td data-label="Attempt / credit">{{ $row['attempt_or_credit'] }}</td>
                                <td data-label="Remarks">{{ str($row['remarks'])->headline() }}</td>
                            </tr>
                            @if ($row['term_summary'])
                                <tr>
                                    <td colspan="4"><strong>{{ $row['term'] }} units</strong></td>
                                    <td colspan="3">{{ $row['term_summary']['term_units'] }} term units · {{ $row['term_summary']['cumulative_earned_units'] }} cumulative earned units</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    <section aria-labelledby="completion-conferral">
        <h2 id="completion-conferral" class="finance-heading">Completion and conferral</h2>
        <p><strong>{{ $conferral['degree'] }}</strong> · Conferred {{ \Carbon\CarbonImmutable::parse($conferral['conferred_on'])->format('F j, Y') }}</p>
        @if (filled($conferral['honor']))
            <p>Recorded honor: {{ $conferral['honor'] }}</p>
        @endif
    </section>

    <section aria-labelledby="certification">
        <h2 id="certification" class="finance-heading">Registrar certification</h2>
        <p>{{ $certification['statement'] }}</p>
        <p><strong>{{ $certification['signatory_name'] }}</strong><br>{{ $certification['signatory_title'] }}</p>
        <p><strong>Institutional seal area:</strong> {{ $certification['seal_placement_instruction'] ?: 'Private seal image is applied in this controlled output area.' }}</p>
        <p class="official-output-notice">This generated record does not claim physical signature, physical sealing, Certified True Copy status, delivery, or CAV readiness.</p>
    </section>

    <section aria-labelledby="legend">
        <h2 id="legend" class="finance-heading">Grading and remarks legend</h2>
        <p>Numeric final results and approved marks reproduce the released academic record. Dropped, withdrawn, INC, approved-credit, and supersession context remain explicit where applicable.</p>
        <p><strong>Transcript reference:</strong> {{ $document['reference'] }} · <strong>Request:</strong> {{ $request->external_request_reference }} · <strong>Template:</strong> {{ $document['template_version'] }}</p>
        <p>Generated through TALA</p>
    </section>
</x-official-output-layout>
