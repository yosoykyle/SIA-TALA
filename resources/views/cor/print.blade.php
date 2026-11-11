@php
    $summary = $cor['summary'];
    $number = fn ($value) => is_numeric($value) ? (string) (float) $value : $value;
    $money = fn ($value) => $value === null ? 'Not recorded' : 'PHP '.number_format((float) $value, 2);
    $courses = collect($cor['subjects'])->groupBy(fn ($subject) => $subject['course_enrollment_id'] ?? $subject['subject_code'].'|'.$subject['section']);
    $recorded = fn ($value): bool => filled($value) && ! in_array($value, ['Not recorded', 'Not applicable'], true);
    $modeLabel = fn ($mode) => match ($mode) { 'FACE_TO_FACE' => 'Face to face', 'ONLINE' => 'Online', 'HYBRID' => 'Hybrid', default => str($mode)->replace('_', ' ')->headline() };
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
            <div class="cor-title"><h1>Certificate of Registration</h1><p>{{ str($cor['copy_context'])->replace('_', ' ')->headline() }} · {{ $summary['document_status'] }} · Issued {{ $summary['registration_date'] ?? 'date not recorded' }}</p></div>
        </header>
    </x-slot:documentHeader>

    <dl class="cor-identity" aria-label="Student, program and term">
        <div class="cor-identity-name"><dt>Student</dt><dd>{{ $summary['student_name'] }}</dd></div>
        <div><dt>Student number</dt><dd>{{ $summary['student_number'] }}</dd></div>
        <div><dt>Program</dt><dd>{{ $summary['program_name'] }}@if ($recorded($summary['program_code'])) <span class="cor-secondary">{{ $summary['program_code'] }}</span>@endif</dd></div>
        <div><dt>Year level</dt><dd>{{ $summary['curriculum_levels'] }}</dd></div>
        <div><dt>Term</dt><dd>{{ $summary['term'] }}</dd></div>
    </dl>

    <div class="cor-body">
        <section class="cor-subjects" aria-label="Official subjects and schedule">
            <div class="cor-subject-table" role="region" aria-label="Subject table. Scroll horizontally on a small screen." tabindex="0">
                <table>
                    <caption class="cor-sr-only">Official subjects and schedule at issue</caption>
                    <colgroup><col class="cor-title-column"><col class="cor-section-column"><col class="cor-units-column"><col class="cor-schedule-column"></colgroup>
                    <thead><tr><th scope="col">Subject</th><th scope="col">Class / Mode</th><th scope="col" class="cor-number">Units</th><th scope="col">Schedule and room</th></tr></thead>
                    <tbody>
                        @forelse ($courses as $meetings)
                            @php($subject = $meetings->first())
                            <tr>
                                <th scope="row">{{ $subject['subject_description'] }}<span class="cor-secondary">{{ collect([$subject['subject_code'], ...$meetings->pluck('instructor')->unique()->filter(fn ($instructor): bool => $recorded($instructor) && $instructor !== 'Externally arranged')->all()])->implode(' · ') }}</span></th>
                                <td>{{ $subject['section'] }}<span class="cor-secondary">{{ $meetings->pluck('modality')->unique()->map($modeLabel)->implode('; ') }}</span></td>
                                <td class="cor-number">{{ $number($subject['units']) }}@if (is_numeric($subject['lecture_hours']) || is_numeric($subject['laboratory_hours']))<span class="cor-secondary">{{ collect([is_numeric($subject['lecture_hours']) ? 'Lec '.$number($subject['lecture_hours']) : null, is_numeric($subject['laboratory_hours']) ? 'Lab '.$number($subject['laboratory_hours']) : null])->filter()->implode(' · ') }}</span>@endif</td>
                                <td>
                                    @foreach ($meetings as $meeting)
                                        <div class="cor-meeting"><span>{{ trim($meeting['day'].' '.($meeting['time'] === 'Not applicable' ? '' : $meeting['time'])) }}</span>@if ($recorded($meeting['room']))<span class="cor-secondary">{{ $meeting['room'] }}</span>@endif</div>
                                    @endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4">No subjects are recorded on this issued copy.</td></tr>
                        @endforelse
                    </tbody>
                    <tfoot><tr><th scope="row" colspan="2">Total units</th><td class="cor-number">{{ $number($summary['total_units']) }}</td><td></td></tr></tfoot>
                </table>
            </div>
        </section>
        <section class="cor-assessment" aria-labelledby="assessment-heading">
            <h2 id="assessment-heading">Assessment at issue</h2>
            <table><caption class="cor-sr-only">Recorded assessment breakdown</caption>
                <tbody>
                    @forelse ($cor['fees'] as $fee)
                        <tr><th scope="row">{{ $fee['label'] }}</th><td class="cor-number">{{ $money($fee['amount']) }}</td></tr>
                    @empty
                        <tr><td colspan="2">No fee breakdown was recorded.</td></tr>
                    @endforelse
                </tbody>
                <tfoot><tr><th scope="row">Total assessment</th><td class="cor-number">{{ $money($summary['assessment_total']) }}</td></tr></tfoot>
            </table>
            @if ($summary['accounting_review_pending'])
                <p class="cor-review">Accounting review pending for the recorded subject change. This assessment has not been recalculated.</p>
            @endif
            <p class="cor-assessment-note">Payments made after issue appear on your Statement of Account.</p>
        </section>
    </div>

    <section class="cor-sources" aria-label="Recorded authority and sources">
        <dl>
            <div><dt>Document reference</dt><dd>COR-{{ $summary['enrollment_id'] }}-{{ $summary['cor_version'] }}</dd></div>
            <div><dt>Recorded by</dt><dd>{{ $summary['issued_by'] }}</dd></div>
            <div><dt>Recorded at</dt><dd>{{ $summary['issued_at'] ? \App\Support\DisplayDateTime::format(\Carbon\CarbonImmutable::parse($summary['issued_at']), 'M j, Y g:i A') : 'Not recorded' }}</dd></div>
            <div><dt>Curriculum record</dt><dd>{{ $summary['curriculum_version'] }}</dd></div>
            <div><dt>Subject selection</dt><dd>{{ str($summary['selection_basis'])->headline() }}</dd></div>
            <div><dt>Timetable source</dt><dd>{{ $summary['course_delivery_mix'] }}</dd></div>
            <div><dt>Term Account</dt><dd>{{ $summary['term_account_reference'] }}</dd></div>
            <div><dt>Clearance at issue</dt><dd>{{ $summary['payment_status'] }}</dd></div>
            <div><dt>Assessment source</dt><dd>{{ $summary['assessment_reference'] }}</dd></div>
            <div><dt>Basis</dt><dd>{{ str($summary['assessment_basis'])->headline() }}</dd></div>
            <div><dt>Authority</dt><dd>{{ $summary['assessment_authority_reference'] }}</dd></div>
            @if ($summary['assessment_authority_date'])<div><dt>Authority date</dt><dd>{{ \Carbon\CarbonImmutable::parse($summary['assessment_authority_date'])->format('M j, Y') }}</dd></div>@endif
        </dl>
    </section>
    <x-slot:documentFooter>
        <footer class="cor-footer"><p>Issued details are fixed. Current payments and balances appear in Finance / Statement of Account.</p><p>This document contains personal information. Keep it secure.</p></footer>
    </x-slot:documentFooter>
</x-official-output-layout>
