<x-official-output-layout
    title="Payment Acknowledgement"
    context="Ledger Posting Confirmation"
    :subtitle="$acknowledgement['summary']['term']"
    :generated-at="\App\Support\DisplayDateTime::format($acknowledgement['generated_at'], 'M d, Y h:i A')"
>
    @php
        $formatPaymentLabel = static fn (?string $value): string => match ($value) {
            'paymongo' => 'PayMongo',
            'gcash' => 'GCash',
            'gcash_manual' => 'GCash Manual',
            default => str($value ?: 'not recorded')->replace('_', ' ')->headline()->toString(),
        };
        $paymentMethod = $formatPaymentLabel($acknowledgement['summary']['method']);
        $paymentChannel = $formatPaymentLabel($acknowledgement['summary']['channel']);
        $paymentMethodAndChannel = str($paymentMethod)->lower()->toString() === str($paymentChannel)->lower()->toString()
            ? $paymentMethod
            : "{$paymentMethod} / {$paymentChannel}";
    @endphp

    <p>
        This document confirms that Accounting posted the payment to the student ledger. It is not an official receipt or OR record.
    </p>

    <div class="official-output-table">
        <table>
            <tbody>
                <tr><th>Student Number</th><td>{{ $acknowledgement['summary']['student_number'] }}</td></tr>
                <tr><th>Student Name</th><td>{{ $acknowledgement['summary']['student_name'] }}</td></tr>
                <tr><th>Program</th><td>{{ $acknowledgement['summary']['program'] }}</td></tr>
                <tr><th>Year Level</th><td>{{ $acknowledgement['summary']['year_level'] }}</td></tr>
                <tr><th>Section</th><td>{{ $acknowledgement['summary']['section'] }}</td></tr>
                <tr><th>Term</th><td>{{ $acknowledgement['summary']['term'] }}</td></tr>
                <tr><th>Payment Amount</th><td>PHP {{ number_format((float) $acknowledgement['summary']['amount'], 2) }}</td></tr>
                <tr><th>Payment Date</th><td>{{ \App\Support\DisplayDateTime::format($acknowledgement['summary']['paid_at'], 'M d, Y h:i A') }}</td></tr>
                <tr><th>Method and Channel</th><td>{{ $paymentMethodAndChannel }}</td></tr>
                <tr><th>Payment Reference</th><td>{{ $acknowledgement['summary']['provider_reference'] ?? 'Manual payment' }}</td></tr>
                <tr><th>Ledger Entry</th><td>#{{ $acknowledgement['summary']['ledger_entry_id'] }}</td></tr>
                <tr><th>Posting Status</th><td>Verified and Posted</td></tr>
                <tr><th>Official Receipt Status</th><td>{{ $acknowledgement['summary']['or_mapping_state'] }}</td></tr>
            </tbody>
        </table>
    </div>

    @if (count($acknowledgement['summary']['allocation_rows']) > 0)
        <h2>Payment Allocation</h2>
        <div class="official-output-table">
            <table>
                <thead>
                    <tr>
                        <th>Applied To</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($acknowledgement['summary']['allocation_rows'] as $allocation)
                        <tr>
                            <td>{{ $allocation['target'] }}</td>
                            <td>{{ $allocation['amount'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="official-output-notice">{{ $acknowledgement['disclaimer'] }}</div>
</x-official-output-layout>
