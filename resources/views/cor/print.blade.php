<x-official-output-layout
    title="Certificate of Registration"
    :context="str($cor['copy_context'])->replace('_', ' ')->headline().' · '.$cor['summary']['document_status'].' · Version '.$cor['summary']['cor_version']"
    :subtitle="$cor['summary']['term']"
    :generated-at="\App\Support\DisplayDateTime::format($cor['generated_at'], 'M d, Y h:i A')"
    :logo-src="asset('images/brand/servitech-crest.webp')"
    page-margin="12mm"
>
    <section class="finance-grid" aria-label="Registration identity and authority">
        <div><strong>Student No.:</strong> {{ $cor['summary']['student_number'] }}</div>
        <div><strong>Legal name:</strong> {{ $cor['summary']['student_name'] }}</div>
        <div><strong>Program:</strong> {{ $cor['summary']['program'] }}</div>
        <div><strong>Curriculum Version:</strong> {{ $cor['summary']['curriculum_version'] }}</div>
        <div><strong>Represented level:</strong> {{ $cor['summary']['curriculum_levels'] }}</div>
        <div><strong>Selection basis:</strong> {{ str($cor['summary']['selection_basis'])->headline() }}</div>
        <div><strong>Registration date:</strong> {{ $cor['summary']['registration_date'] }}</div>
        <div><strong>Total units:</strong> {{ $cor['summary']['total_units'] }}</div>
        <div><strong>Timetable source:</strong> {{ $cor['summary']['course_delivery_mix'] }}</div>
        <div><strong>Assessment source:</strong> {{ $cor['summary']['assessment_reference'] }}</div>
        <div><strong>Term Account:</strong> {{ $cor['summary']['term_account_reference'] }}</div>
        <div><strong>Clearance at issue:</strong> {{ $cor['summary']['payment_status'] }}</div>
        <div><strong>Recorded authority:</strong> {{ $cor['summary']['issued_by'] }}</div>
        <div><strong>Recorded at:</strong> {{ \App\Support\DisplayDateTime::format(\Carbon\CarbonImmutable::parse($cor['summary']['issued_at']), 'M d, Y h:i A') }}</div>
    </section>

    <section>
        <h2 class="finance-heading">Official courses and class schedule</h2>
        <div class="official-output-table">
            <table>
                <thead>
                    <tr>
                        <th>Course</th>
                        <th>Title</th>
                        <th>Units</th>
                        <th>Contact hours</th>
                        <th>Class</th>
                        <th>Schedule</th>
                        <th>Room</th>
                        <th>Faculty</th>
                        <th>Mode</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($cor['subjects'] as $subject)
                        <tr>
                            <td>{{ $subject['subject_code'] }}</td>
                            <td>{{ $subject['subject_description'] }}</td>
                            <td>{{ $subject['units'] }}</td>
                            <td>Lecture {{ $subject['lecture_hours'] }}; Laboratory {{ $subject['laboratory_hours'] }}</td>
                            <td>{{ $subject['section'] }}</td>
                            <td>{{ $subject['day'] }}<br>{{ $subject['time'] }}</td>
                            <td>{{ $subject['room'] }}</td>
                            <td>{{ $subject['instructor'] }}</td>
                            <td>{{ $subject['modality'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9">No official course snapshot is recorded for this version.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2 class="finance-heading">Assessment at finalization</h2>
        <p>
            <strong>Total assessed:</strong>
            {{ $cor['summary']['assessment_total'] !== null ? 'PHP '.number_format((float) $cor['summary']['assessment_total'], 2) : 'Not recorded' }}
        </p>
        <div class="official-output-table">
            <table>
                <thead><tr><th>Category</th><th>Amount</th></tr></thead>
                <tbody>
                    @forelse ($cor['fees'] as $fee)
                        <tr><td>{{ $fee['label'] }}</td><td>PHP {{ number_format((float) $fee['amount'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="2">No assessment-category snapshot was recorded for this version.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="official-output-notice">
            This COR records assessment and clearance only as they stood at finalization. Current balances, later payments,
            receipts, reversals, and future installments remain in Student Finance and the authenticated Statement of Account.
        </div>
    </section>
</x-official-output-layout>
