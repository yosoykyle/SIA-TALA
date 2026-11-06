<x-official-output-layout title="TRANSCRIPT OF RECORDS" :context="strtoupper($status)" document-class="tala-transcript-paper">
    <x-slot:documentStyles>
        <style>
            .official-output-toolbar { display:none; }
            .official-output { max-width:210mm; box-sizing:border-box; }
            .tor-paper-heading { display:flex; align-items:center; justify-content:center; gap:16px; border-bottom:2px solid #1f5132; padding-bottom:16px; text-align:center; }
            .tor-paper-heading img { width:60px; height:60px; object-fit:contain; }
            .tor-paper-heading p,.tor-paper-heading h1 { margin:4px 0; }
            .tor-paper-heading h1 { font-size:20px; letter-spacing:.04em; }
            .tor-paper-status { text-align:center; margin:12px 0; font-weight:bold; }
            .tor-paper-identity { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px 24px; margin:20px 0; }
            .tor-paper-identity dt { font-size:11px; color:#4b5563; margin-bottom:3px; }
            .tor-paper-identity dd { margin:0; font-weight:bold; }
            .tor-paper-year { margin-top:20px; }
            .tor-paper-year h2 { font-size:14px; margin:0 0 8px; }
            .tor-paper-year table { font-size:11px; }
            .tor-paper-year td,.tor-paper-year th { padding:6px; }
            .tor-paper-numeric { text-align:right; font-variant-numeric:tabular-nums; }
            .tor-paper-certification { margin-top:24px; break-inside:avoid; }
            .tor-paper-signature { display:flex; justify-content:space-between; align-items:end; gap:24px; margin-top:36px; }
            .tor-paper-seal { border:1px dashed #6b7280; width:30mm; height:25mm; display:grid; place-items:center; color:#4b5563; font-size:10px; text-align:center; }
            .tor-paper-legend { font-size:10px; color:#374151; margin-top:20px; }
            .tor-paper-footer { border-top:1px solid #6b7280; margin-top:12px; padding-top:5px; font-size:10px; overflow-wrap:anywhere; break-inside:avoid; }
            .tor-paper-footer p { margin:0; }
            .tor-paper-footer p + p { margin-top:2px; color:#374151; }
            @media screen and (max-width:600px) { .tor-paper-identity { grid-template-columns:1fr; } .tor-paper-year { overflow-x:auto; } }
            @media print {
                .tor-paper-year :is(td, th) { padding:4px 6px; }
                .tor-paper-heading { padding-bottom:10px; }
                .tor-paper-status { margin-block:8px; }
                .tor-paper-identity {
                    grid-template-columns:repeat(2,minmax(0,1fr));
                    row-gap:8px;
                    margin-block:12px;
                }
                .tor-paper-identity dt { margin-bottom:2px; }
                .tor-paper-identity + .tor-paper-year { margin-top:12px; }
            }
        </style>
    </x-slot:documentStyles>
    <x-slot:documentHeader>
        <header class="tor-paper-heading"><img src="{{ asset('images/brand/servitech-crest.webp') }}" alt="{{ $institution['name'] }} logo"><div><p><strong>{{ $institution['name'] }}</strong></p><p>{{ $institution['address'] }}</p><p>{{ $institution['phone'] ?: $institution['facebook_url'] }}</p><h1>TRANSCRIPT OF RECORDS</h1></div></header>
        <p class="tor-paper-status">{{ strtoupper($status) }} · {{ $document['reference'] }}</p>
    </x-slot:documentHeader>
    <dl class="tor-paper-identity">
        <div><dt>Student</dt><dd>{{ $student['legal_name'] }}</dd></div><div><dt>Student number</dt><dd>{{ $student['student_number'] }}</dd></div>
        <div><dt>Program</dt><dd>{{ $student['program'] }}</dd></div><div><dt>Curriculum</dt><dd>{{ $student['curriculum_version'] }}</dd></div>
        <div><dt>Admission</dt><dd>{{ $student['admission_basis'] }}@if ($student['admission_date']) · {{ $student['admission_date'] }}@endif</dd></div>
        @if ($student['prior_school_or_credit'])<div><dt>Prior school / credit</dt><dd>{{ $student['prior_school_or_credit'] }}</dd></div>@endif
    </dl>
    @foreach ($academic_years as $academicYear => $rows)
        <section class="tor-paper-year"><h2>{{ $academicYear }}</h2><table>
            <thead><tr><th colspan="7">{{ $student['legal_name'] }} · {{ $student['student_number'] }} · {{ $document['reference'] }}</th></tr><tr><th>Term</th><th>Code</th><th>Subject</th><th>Units</th><th>Result</th><th>Attempt / credit</th><th>Remarks</th></tr></thead>
            <tbody>@foreach ($rows as $row)<tr><td>{{ $row['term'] }}</td><td>{{ $row['course_code'] }}</td><td>{{ $row['course_title'] }}</td><td class="tor-paper-numeric">{{ number_format((float) $row['units'],2) }}</td><td class="tor-paper-numeric"><strong>{{ $row['result'] }}</strong></td><td>{{ $row['attempt_or_credit'] }}</td><td>{{ str($row['remarks'])->headline() }}</td></tr>
                @if ($row['term_summary'])<tr><td colspan="7"><strong>{{ $row['term'] }}</strong> · {{ $row['term_summary']['term_units'] }} term units · {{ $row['term_summary']['cumulative_earned_units'] }} cumulative earned units</td></tr>@endif
            @endforeach</tbody>
        </table></section>
    @endforeach
    @if (filled($conferral['degree'] ?? null))<section class="tor-paper-certification"><h2>Recorded graduation</h2><p><strong>{{ $conferral['degree'] }}</strong>@if ($conferral['conferred_on']) · {{ \Carbon\CarbonImmutable::parse($conferral['conferred_on'])->format('F j, Y') }}@endif</p>@if ($conferral['honor'])<p>{{ $conferral['honor'] }}</p>@endif</section>@endif
    <section class="tor-paper-certification"><p>{{ $certification['statement'] }}</p><div class="tor-paper-signature"><p><strong>{{ $certification['signatory_name'] }}</strong><br>{{ $certification['signatory_title'] }}</p><div class="tor-paper-seal">Institutional seal</div></div></section>
    <p class="tor-paper-legend">Results reproduce the released academic record. INC means incomplete. Dropped, withdrawn, approved-credit and repeat-attempt remarks are retained where applicable. Printing does not certify that the document has been physically signed, sealed or delivered.</p>
    <x-slot:documentFooter>
        <footer class="tor-paper-footer">
            <p><strong>Document reference</strong> · {{ $document['reference'] }}</p>
            <p>{{ $document['generated_at'] }} · {{ $document['template_version'] }} · {{ $document['generation_reference'] }} · Generated through TALA</p>
        </footer>
    </x-slot:documentFooter>
</x-official-output-layout>
