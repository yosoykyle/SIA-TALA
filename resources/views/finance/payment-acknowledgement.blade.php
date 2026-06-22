<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Acknowledgement</title>
    <style>
        body { color: #18181b; font-family: Arial, sans-serif; margin: 32px auto; max-width: 720px; padding: 0 20px; }
        h1 { font-size: 24px; margin-bottom: 24px; }
        dl { display: grid; grid-template-columns: 180px 1fr; margin: 0; }
        dt, dd { border-bottom: 1px solid #e4e4e7; margin: 0; padding: 10px 0; }
        dt { color: #52525b; font-weight: bold; }
        .amount { font-size: 22px; font-weight: bold; }
        .notice { border: 1px solid #a1a1aa; font-size: 13px; margin-top: 28px; padding: 12px; }
        @media print { body { margin: 0; max-width: none; } }
    </style>
</head>
<body>
    <h1>Payment Acknowledgement</h1>
    <dl>
        <dt>Student</dt><dd>{{ $payment->studentProfile->user->name }}</dd>
        <dt>Student ID</dt><dd>{{ $payment->studentProfile->student_id }}</dd>
        <dt>Term</dt><dd>{{ $payment->term?->term_name ?? 'Not assigned' }}</dd>
        <dt>Reference</dt><dd>{{ $payment->payment_reference }}</dd>
        <dt>Channel</dt><dd>{{ str($payment->channel)->headline() }}</dd>
        <dt>Status</dt><dd>{{ str($payment->status)->headline() }}</dd>
        <dt>Confirmed</dt><dd>{{ $payment->confirmed_at?->format('M d, Y h:i A') }}</dd>
        <dt>Amount</dt><dd class="amount">PHP {{ number_format((float) $payment->amount, 2) }}</dd>
        <dt>Generated</dt><dd>{{ $generated_at->format('M d, Y h:i A') }}</dd>
    </dl>
    <div class="notice">{{ $disclaimer }}</div>
</body>
</html>
