@php
    $summary = $cor['summary'];
    $number = fn ($value) => is_numeric($value) ? (string) (float) $value : $value;
    $money = fn ($value) => $value === null ? 'Not recorded' : 'PHP '.number_format((float) $value, 2);
    $courses = collect($cor['subjects'])->groupBy(fn ($subject) => $subject['course_enrollment_id'] ?? $subject['subject_code'].'|'.$subject['section']);
@endphp
<x-official-output-layout
    title="Certificate of Registration"
    :context="str($cor['copy_context'])->replace('_', ' ')->headline().' · '.$summary['document_status']"
    page-orientation="landscape"
    page-margin="8mm"
    document-class="cor-document"
    :back-url="$backUrl ?? null"
    :back-label="$backLabel ?? 'Back to enrollment'"
>
    <x-slot:documentStyles><link rel="stylesheet" href="{{ asset('css/tala-cor.css').'?v='.filemtime(public_path('css/tala-cor.css')) }}"></x-slot:documentStyles>
    <x-slot:documentHeader>
        <header class="cor-header">
            <div class="cor-institution">
                <img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="{{ config('institution.name') }} crest" width="64" height="64">
                <div><p>{{ config('institution.name') }}</p>
                    @if (filled(config('institution.address')))<p class="cor-institution-address">{{ config('institution.address') }}</p>@endif
                </div>
            </div>
            <div class="cor-title"><h1>Certificate of Registration</h1><p>{{ str($cor['copy_context'])->replace('_', ' ')->headline() }} · {{ $summary['document_status'] }}</p></div>
        </header>
    </x-slot:documentHeader>

    <section class="cor-student" aria-label="Student and term">
        <dl><div><dt>Student name</dt><dd class="cor-student-name">{{ $summary['student_name'] }}</dd></div><div><dt>Student number</dt><dd>{{ $summary['student_number'] }}</dd></div></dl>
        <dl><div><dt>Term</dt><dd>{{ $summary['term'] }}</dd></div><div><dt>Date issued</dt><dd>{{ $summary['registration_date'] ?? 'Not recorded' }}</dd></div></dl>
    </section>
    <dl class="cor-registration" aria-label="Program and curriculum">
        <div class="cor-program"><dt>Program</dt><dd>{{ $summary['program_name'] }}</dd></div>
        <div><dt>Program code</dt><dd>{{ $summary['program_code'] }}</dd></div>
        <div class="cor-levels"><dt>Year level</dt><dd>{{ $summary['curriculum_levels'] }}</dd></div>
    </dl>

    <div class="cor-body">
        <section class="cor-subjects" aria-label="Official subjects and schedule">
            <div class="cor-subject-table" role="region" aria-label="Subject table. Scroll horizontally on a small screen." tabindex="0">
                <table>
                    <caption class="cor-sr-only">Official subjects and schedule at issue</caption>
                    <colgroup><col class="cor-code-column"><col class="cor-title-column"><col class="cor-section-column"><col class="cor-units-column"><col class="cor-schedule-column"></colgroup>
                    <thead><tr><th scope="col">Subject code</th><th scope="col">Subject title / Faculty</th><th scope="col">Section / Mode</th><th scope="col" class="cor-number">Units<br><span>Contact hours</span></th><th scope="col">Schedule and room</th></tr></thead>
                    <tbody>
                        @forelse ($courses as $meetings)
                            @php($subject = $meetings->first())
                            <tr>
                                <td>{{ $subject['subject_code'] }}</td>
                                <th scope="row">{{ $subject['subject_description'] }}<span class="cor-secondary">{{ $meetings->pluck('instructor')->unique()->implode('; ') }}</span></th>
                                <td>{{ $subject['section'] }}<span class="cor-secondary">{{ $meetings->pluck('modality')->unique()->map(fn ($mode) => match ($mode) { 'FACE_TO_FACE' => 'Face to face', 'ONLINE' => 'Online', 'HYBRID' => 'Hybrid', default => str($mode)->replace('_', ' ')->headline() })->implode('; ') }}</span></td>
                                <td class="cor-number">{{ $number($subject['units']) }}<span class="cor-secondary">Lec {{ $number($subject['lecture_hours']) }}<br>Lab {{ $number($subject['laboratory_hours']) }}</span></td>
                                <td>
                                    @foreach ($meetings as $meeting)
                                        <div class="cor-meeting"><span>{{ $meeting['day'] }} {{ $meeting['time'] === 'Not applicable' ? '' : $meeting['time'] }}</span><span class="cor-secondary">{{ $meeting['room'] }}</span></div>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5">No subjects are recorded on this issued copy.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="cor-assessment" aria-labelledby="assessment-heading">
            <h2 id="assessment-heading">Assessment at issue</h2>
            <table><caption class="cor-sr-only">Recorded assessment breakdown</caption>
                <thead><tr><th scope="col">Description</th><th scope="col" class="cor-number">Amount</th></tr></thead>
                <tbody>
                    @forelse ($cor['fees'] as $fee)
                        <tr><th scope="row">{{ $fee['label'] }}</th><td class="cor-number">{{ $money($fee['amount']) }}</td></tr>
                    @empty
                        <tr><td colspan="2">No fee breakdown was recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <dl class="cor-assessment-source">
                <div><dt>Assessment source</dt><dd>{{ $summary['assessment_reference'] }}</dd></div>
                <div><dt>Basis</dt><dd>{{ str($summary['assessment_basis'])->headline() }}</dd></div>
                <div><dt>Authority</dt><dd>{{ $summary['assessment_authority_reference'] }}</dd></div>
                @if ($summary['assessment_authority_date'])<div><dt>Authority date</dt><dd>{{ \Carbon\CarbonImmutable::parse($summary['assessment_authority_date'])->format('M j, Y') }}</dd></div>@endif
            </dl>
            @if ($summary['accounting_review_pending'])
                <p class="cor-review">Accounting review pending for the recorded subject change. This assessment has not been recalculated.</p>
            @endif
        </section>
    </div>
    <div class="cor-totals"><p><strong>Total units</strong><span>{{ $number($summary['total_units']) }}</span></p><p><strong>Total assessment</strong><span>{{ $money($summary['assessment_total']) }}</span></p></div>

    <section class="cor-sources" aria-label="Recorded authority and sources">
        <dl><div><dt>Document reference</dt><dd>COR-{{ $summary['enrollment_id'] }}-{{ $summary['cor_version'] }}</dd></div><div><dt>Curriculum record</dt><dd>{{ $summary['curriculum_version'] }}</dd></div><div><dt>Subject selection</dt><dd>{{ str($summary['selection_basis'])->headline() }}</dd></div><div><dt>Timetable source</dt><dd>{{ $summary['course_delivery_mix'] }}</dd></div><div><dt>Term Account</dt><dd>{{ $summary['term_account_reference'] }}</dd></div><div><dt>Clearance at issue</dt><dd>{{ $summary['payment_status'] }}</dd></div></dl>
        <dl><div><dt>Recorded by</dt><dd>{{ $summary['issued_by'] }}</dd></div><div><dt>Recorded at</dt><dd>{{ $summary['issued_at'] ? \App\Support\DisplayDateTime::format(\Carbon\CarbonImmutable::parse($summary['issued_at']), 'M j, Y g:i A') : 'Not recorded' }}</dd></div></dl>
    </section>
    <x-slot:documentFooter>
        <footer class="cor-footer"><p>Issued details are fixed. Current payments and balances appear in Finance / Statement of Account.</p><p>This document contains personal information. Keep it secure.</p></footer>
    </x-slot:documentFooter>
</x-official-output-layout>
