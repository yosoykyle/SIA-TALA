<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement of Account</title>
    <style>
        body { color: #18181b; font-family: Arial, sans-serif; margin: 32px auto; max-width: 960px; padding: 0 20px; }
        h1 { font-size: 24px; margin-bottom: 4px; }
        .meta { color: #52525b; font-size: 14px; line-height: 1.6; }
        table { border-collapse: collapse; margin-top: 24px; width: 100%; }
        th, td { border-bottom: 1px solid #d4d4d8; padding: 10px 8px; text-align: left; }
        th { background: #f4f4f5; font-size: 13px; }
        .amount { text-align: right; white-space: nowrap; }
        .totals { margin-left: auto; margin-top: 20px; width: min(100%, 360px); }
        .totals div { display: flex; justify-content: space-between; padding: 5px 0; }
        .balance { border-top: 2px solid #18181b; font-weight: bold; margin-top: 4px; padding-top: 10px !important; }
        .notice { border: 1px solid #a1a1aa; font-size: 13px; margin-top: 28px; padding: 12px; }
        @media print { body { margin: 0; max-width: none; } }
    </style>
</head>
<body>
    <h1>Statement of Account</h1>
    <div class="meta">
        <div><strong>Student:</strong> {{ $enrollment->studentProfile->user->name }}</div>
        <div><strong>Student ID:</strong> {{ $enrollment->studentProfile->student_id }}</div>
        <div><strong>Program:</strong> {{ $enrollment->studentProfile->program?->code ?? 'Not assigned' }}</div>
        <div><strong>Term:</strong> {{ $enrollment->term->term_name }}</div>
        <div><strong>Generated:</strong> {{ $generated_at->format('M d, Y h:i A') }}</div>
    </div>

    <table>
        <thead><tr><th>Date</th><th>Entry</th><th>Description</th><th class="amount">Amount</th><th class="amount">Balance</th></tr></thead>
        <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td>{{ $entry->posted_at?->format('M d, Y') }}</td>
                <td>{{ str($entry->entry_type)->headline() }}</td>
                <td>{{ $entry->description }}</td>
                <td class="amount">PHP {{ number_format((float) $entry->amount, 2) }}</td>
                <td class="amount">PHP {{ number_format((float) $entry->running_balance, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No account entries recorded for this enrollment.</td></tr>
        @endforelse
        </tbody>
    </table>

    <div class="totals">
        <div><span>Total charges</span><strong>PHP {{ number_format((float) $total_charges, 2) }}</strong></div>
        <div><span>Total credits</span><strong>PHP {{ number_format((float) $total_credits, 2) }}</strong></div>
        <div class="balance"><span>Balance</span><span>PHP {{ number_format((float) $balance, 2) }}</span></div>
    </div>

    <div class="notice">{{ $disclaimer }}</div>
</body>
</html>
