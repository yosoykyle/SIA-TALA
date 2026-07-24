<x-official-output-layout
    title="Payment Acknowledgement"
    context="Ledger Posting Confirmation"
    :subtitle="$acknowledgement['summary']['term']"
    :generated-at="$acknowledgement['generated_at']->format('M d, Y h:i A')"
>
    <p>
        This document confirms that Accounting posted the payment to the student ledger. It is not an official receipt or OR record.
    </p>

    <div class="official-output-table">
        <table>
            <tbody>
                <tr><th>Student Number</th><td>{{ $acknowledgement['summary']['student_number'] }}</td></tr>
                <tr><th>Student Name</th><td>{{ $acknowledgement['summary']['student_name'] }}</td></tr>
                <tr><th>Program</th><td>{{ $acknowledgement['summary']['program'] }}</td></tr>
                <tr><th>Term</th><td>{{ $acknowledgement['summary']['term'] }}</td></tr>
                <tr><th>Payment Amount</th><td>PHP {{ number_format((float) $acknowledgement['summary']['amount'], 2) }}</td></tr>
                <tr><th>Payment Date</th><td>{{ $acknowledgement['summary']['paid_at']?->format('M d, Y h:i A') ?? 'Not recorded' }}</td></tr>
                <tr><th>Method and Channel</th><td>{{ $acknowledgement['summary']['method'] }} / {{ $acknowledgement['summary']['channel'] }}</td></tr>
                <tr><th>Payment Reference</th><td>{{ $acknowledgement['summary']['provider_reference'] ?? 'Manual payment' }}</td></tr>
                <tr><th>Ledger Entry</th><td>#{{ $acknowledgement['summary']['ledger_entry_id'] }}</td></tr>
                <tr><th>Posting Status</th><td>Verified and Posted</td></tr>
                <tr><th>Official Receipt Status</th><td>{{ $acknowledgement['summary']['or_mapping_state'] }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="official-output-notice">{{ $acknowledgement['disclaimer'] }}</div>
</x-official-output-layout>
