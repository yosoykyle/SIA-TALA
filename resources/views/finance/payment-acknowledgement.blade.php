<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Acknowledgement</title>
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
        <h1>Payment Acknowledgement</h1>
        <div>SERVITECH INSTITUTE ASIA INC.</div>
        <div>Internal billing verification</div>
    </header>

    <table>
        <tbody>
            <tr><th>Student No.</th><td>{{ $acknowledgement['summary']['student_number'] }}</td></tr>
            <tr><th>Student Name</th><td>{{ $acknowledgement['summary']['student_name'] }}</td></tr>
            <tr><th>Program</th><td>{{ $acknowledgement['summary']['program'] }}</td></tr>
            <tr><th>Term</th><td>{{ $acknowledgement['summary']['term'] }}</td></tr>
            <tr><th>Payment Amount</th><td>PHP {{ number_format((float) $acknowledgement['summary']['amount'], 2) }}</td></tr>
            <tr><th>Payment Date</th><td>{{ $acknowledgement['summary']['paid_at']?->format('Y-m-d H:i') ?? 'Not recorded' }}</td></tr>
            <tr><th>Method / Channel</th><td>{{ $acknowledgement['summary']['method'] }} / {{ $acknowledgement['summary']['channel'] }}</td></tr>
            <tr><th>Payment Reference</th><td>{{ $acknowledgement['summary']['provider_reference'] ?? 'Manual payment' }}</td></tr>
            <tr><th>Ledger Entry</th><td>#{{ $acknowledgement['summary']['ledger_entry_id'] }}</td></tr>
            <tr><th>Confirmation Status</th><td>Verified and Posted</td></tr>
            <tr><th>OR Mapping</th><td>{{ $acknowledgement['summary']['or_mapping_state'] }}</td></tr>
            <tr><th>Generated</th><td>{{ $acknowledgement['generated_at']->format('Y-m-d H:i') }}</td></tr>
        </tbody>
    </table>

    <div class="notice">{{ $acknowledgement['disclaimer'] }}</div>
</body>
</html>
