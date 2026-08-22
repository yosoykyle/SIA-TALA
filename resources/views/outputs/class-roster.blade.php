<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class Roster</title>
    <style>
        @page { size: A4 portrait; margin: 14mm; }
        body { margin: 0; color: #111827; font: 12px/1.45 Arial, sans-serif; }
        header { border-bottom: 2px solid #0f766e; margin-bottom: 16px; padding-bottom: 10px; }
        h1 { font-size: 20px; margin: 0 0 4px; } p { margin: 2px 0; }
        table { border-collapse: collapse; width: 100%; } th, td { border: 1px solid #9ca3af; padding: 7px; text-align: left; }
        th { background: #f0fdfa; } .muted { color: #4b5563; } .no-print { margin-bottom: 12px; }
        @media print { .no-print { display: none; } thead { display: table-header-group; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
    <button class="no-print" type="button" onclick="window.print()">Print roster</button>
    <header>
        <h1>Current Class Roster</h1>
        <p><strong>{{ $roster->termOffering?->curriculumEntry?->courseSpecification?->course?->code }}</strong>
            — {{ $roster->termOffering?->curriculumEntry?->courseSpecification?->title }}</p>
        <p>{{ $roster->termOffering?->term?->label }} · Section {{ $roster->section?->code }}</p>
        <p class="muted">Current official membership as of {{ $generatedAt->format('F j, Y g:i A') }} Asia/Manila. This output contains no grades, contact details, or financial data.</p>
    </header>
    <table>
        <thead><tr><th scope="col">Student number</th><th scope="col">Last name</th><th scope="col">First name</th><th scope="col">Middle name</th></tr></thead>
        <tbody>
            @forelse ($roster->rows as $row)
                @php($student = $row->courseEnrollment?->enrollment?->studentProfile)
                <tr><td>{{ $student?->student_number }}</td><td>{{ $student?->last_name }}</td><td>{{ $student?->first_name }}</td><td>{{ $student?->middle_name }}</td></tr>
            @empty
                <tr><td colspan="4">No current officially enrolled learners.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
