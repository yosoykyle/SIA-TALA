<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Billing Slip</title>
    <style>
        body { color: #111827; font-family: Arial, sans-serif; margin: 32px; }
        header { border-bottom: 2px solid #111827; margin-bottom: 24px; padding-bottom: 12px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d1d5db; font-size: 13px; padding: 10px; text-align: left; }
        th { background: #f3f4f6; width: 36%; }
        .notice { background: #fff7ed; border: 1px solid #fed7aa; margin-top: 24px; padding: 12px; }
        .actions { margin-bottom: 16px; }
        @media print { .actions { display: none; } body { margin: 18mm; } }
    </style>
</head>
<body>
    <div class="actions">
        <a href="{{ request()->fullUrlWithQuery(['print' => 1]) }}">Print / Save as PDF</a>
    </div>

    @if (request()->boolean('print'))
        <script>window.addEventListener('load', () => window.print());</script>
    @endif

    <header>
        <h1>Billing Slip</h1>
        <div>SERVITECH INSTITUTE ASIA INC.</div>
        <div>Internal billing reference only</div>
    </header>

    <table>
        <tbody>
            <tr><th>Student No.</th><td>{{ $slip['summary']['student_number'] }}</td></tr>
            <tr><th>Student Name</th><td>{{ $slip['summary']['student_name'] }}</td></tr>
            <tr><th>Program</th><td>{{ $slip['summary']['program'] }}</td></tr>
            <tr><th>Term</th><td>{{ $slip['summary']['term'] }}</td></tr>
            <tr><th>Due Category</th><td>{{ $slip['state']['current_due_source'] }}</td></tr>
            <tr><th>Amount Due</th><td>{{ $slip['state']['current_due'] }}</td></tr>
            <tr><th>Internal Reference</th><td>TALA-ASSESSMENT-{{ $slip['summary']['assessment_id'] }}</td></tr>
            <tr><th>Generated</th><td>{{ $slip['generated_at']->format('Y-m-d H:i') }}</td></tr>
        </tbody>
    </table>

    <div class="notice">{{ $slip['disclaimer'] }}</div>
</body>
</html>
