<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement of Account</title>
    <style>
        body { color: #111827; font-family: Arial, sans-serif; margin: 32px; }
        header { border-bottom: 2px solid #111827; margin-bottom: 24px; padding-bottom: 12px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 24px 0 8px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d1d5db; font-size: 12px; padding: 8px; text-align: left; }
        th { background: #f3f4f6; }
        .grid { display: grid; gap: 8px 24px; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .summary { margin-top: 16px; }
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
        <h1>Statement of Account</h1>
        <div>SERVITECH INSTITUTE ASIA INC.</div>
        <div>{{ $statement['summary']['term'] }}</div>
        <div>{{ $statement['copy_context'] === 'ACCOUNTING_COPY' ? 'Accounting Copy' : 'Student Copy' }}</div>
    </header>

    <section class="grid">
        <div><strong>Student No.:</strong> {{ $statement['summary']['student_number'] }}</div>
        <div><strong>Full Name:</strong> {{ $statement['summary']['student_name'] }}</div>
        <div><strong>Program:</strong> {{ $statement['summary']['program'] }}</div>
        <div><strong>Generated:</strong> {{ $statement['generated_at']->format('Y-m-d H:i') }}</div>
    </section>

    <section>
        <h2>Fee Computation</h2>
        <table>
            <thead>
                <tr>
                    <th>Description</th>
                    <th>Quantity</th>
                    <th>Rate</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statement['state']['charge_lines'] as $line)
                    <tr>
                        <td>{{ $line['description'] }}</td>
                        <td>{{ $line['quantity'] }}</td>
                        <td>{{ $line['rate'] }}</td>
                        <td>{{ $line['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section>
        <h2>Posted Ledger Entries</h2>
        <table>
            <thead>
                <tr>
                    <th>Posted</th>
                    <th>Direction</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($statement['state']['ledger_rows'] as $entry)
                    <tr>
                        <td>{{ $entry['posted_at'] }}</td>
                        <td>{{ $entry['direction'] }}</td>
                        <td>{{ $entry['category'] }}</td>
                        <td>{{ $entry['description'] }}</td>
                        <td>{{ $entry['amount'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="summary">
        <table>
            <tbody>
                <tr><th>Assessment Total</th><td>{{ $statement['state']['assessment_total'] }}</td></tr>
                <tr><th>Required Downpayment</th><td>{{ $statement['state']['required_downpayment'] }}</td></tr>
                <tr><th>Posted Payments</th><td>{{ $statement['state']['posted_payments'] }}</td></tr>
                <tr><th>Current Balance</th><td>{{ $statement['state']['ledger_balance'] }}</td></tr>
                <tr><th>Status</th><td>{{ $statement['state']['payment_status'] }}</td></tr>
            </tbody>
        </table>
    </section>

    <div class="notice">{{ $statement['disclaimer'] }}</div>
</body>
</html>
