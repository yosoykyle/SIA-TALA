<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unofficial Student Record</title>
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        body { margin: 0; color: #111827; font: 11px/1.45 Arial, sans-serif; }
        header { border-bottom: 3px solid #0f766e; margin-bottom: 18px; padding-bottom: 10px; }
        h1 { font-size: 20px; margin: 0; } h2 { font-size: 15px; margin: 20px 0 7px; }
        p { margin: 3px 0; } table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #9ca3af; padding: 6px; text-align: left; vertical-align: top; }
        th { background: #f0fdfa; } .warning { color: #991b1b; font-weight: 700; }
        .muted { color: #4b5563; } .no-print { margin-bottom: 12px; } .print-page-identity { display: none; }
        @media print {
            body { padding-top: 13mm; }
            .no-print { display: none; }
            .print-page-identity { display: block; position: fixed; top: -8mm; left: 0; right: 0; border-bottom: 1px solid #111827; background: #fff; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="print-page-identity" aria-hidden="true">
        <strong>UNOFFICIAL STUDENT RECORD</strong> · {{ $student->student_number }} ·
        {{ collect([$student->last_name, $student->first_name])->filter()->implode(', ') }}
    </div>
    <button class="no-print" type="button" onclick="window.print()">Print unofficial record</button>
    <header>
        <p class="warning">UNOFFICIAL STUDENT RECORD — NOT A TRANSCRIPT OF RECORDS</p>
        <h1>TALA Student Academics</h1>
        <p><strong>{{ $student->student_number }}</strong> · {{ collect([$student->last_name, $student->first_name, $student->middle_name])->filter()->implode(', ') }}</p>
        <p>{{ $student->program?->code }} · Curriculum {{ $student->curriculumVersion?->version_code }}</p>
        <p class="muted">Released facts as of {{ $asOf->format('F j, Y g:i A') }} Asia/Manila. Later releases or corrections are not represented.</p>
    </header>

    @foreach ($terms as $term)
        <section>
            <h2>{{ $term->label }}</h2>
            <table>
                <thead><tr><th scope="col">Course</th><th scope="col">Title</th><th scope="col">Units</th><th scope="col">Released result</th><th scope="col">Released</th></tr></thead>
                <tbody>
                    @foreach ($results->where('term.id', $term->id) as $result)
                        <tr>
                            <td>{{ $result['course_specification']?->course?->code }}</td>
                            <td>{{ $result['course_specification']?->title }}</td>
                            <td>{{ number_format((float) $result['units'], 2) }}</td>
                            <td>
                                {{ $result['result'] }}
                                @if ($result['result'] === 'INC' && $result['event']?->deadline)
                                    <br><span class="muted">Completion deadline: {{ $result['event']->deadline->format('M j, Y') }}</span>
                                @endif
                                @if ($result['event']?->predecessor_event_id)
                                    <br><span class="muted">Supersedes an earlier released result.</span>
                                @endif
                            </td>
                            <td>{{ $result['event']?->released_at?->format('M j, Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @php($average = $termAverages->get($term->id))
            <p><strong>{{ $average['label'] }}:</strong> {{ $average['value'] ?? $average['reason'] }}</p>
        </section>
    @endforeach

    <h2>Cumulative academic average</h2>
    <p><strong>Cumulative GWA:</strong> {{ $cumulative['value'] ?? 'Unavailable' }}
        @if ($cumulative['through']) · Through {{ $cumulative['through'] }} @endif</p>
    <h2>Curriculum and enrollment guidance</h2>
    <p><strong>Completed curriculum units:</strong> {{ $curriculum['completed_units'] }}</p>
    <p><strong>Remaining requirements:</strong> {{ $curriculum['deficiency_count'] }}</p>
    <p><strong>Current enrollment guidance:</strong> {{ str($academicEffect['effect'])->headline() }}</p>
    <p>{{ $academicEffect['reason'] }} <span class="muted">Source: {{ $academicEffect['source'] }}.</span></p>
    <p class="muted">This authenticated output is for advising and personal reference. Official TOR issuance and conferral are separate Registrar processes.</p>
</body>
</html>
