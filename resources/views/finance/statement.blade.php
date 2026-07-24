<x-official-output-layout
    title="Statement of Account"
    :context="$statement['copy_context'] === 'ACCOUNTING_COPY' ? 'Accounting Copy' : 'Student Copy'"
    :subtitle="$statement['summary']['term']"
    :generated-at="$statement['generated_at']->format('M d, Y h:i A')"
>
    <section class="finance-grid">
        <div><strong>Student Number:</strong> {{ $statement['summary']['student_number'] }}</div>
        <div><strong>Student Name:</strong> {{ $statement['summary']['student_name'] }}</div>
        <div><strong>Program:</strong> {{ $statement['summary']['program'] }}</div>
        <div><strong>Payment Status:</strong> {{ $statement['state']['payment_status'] }}</div>
    </section>

    <section>
        <h2 class="finance-heading">Assessment Charges</h2>
        <div class="official-output-table">
            <table>
                <thead>
                    <tr>
                        <th>Charge</th>
                        <th>Quantity</th>
                        <th>Rate</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($statement['state']['charge_lines'] as $line)
                        <tr>
                            <td>{{ $line['description'] }}</td>
                            <td>{{ $line['quantity'] }}</td>
                            <td>{{ $line['rate'] }}</td>
                            <td>{{ $line['amount'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4">No assessment charges are recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h2 class="finance-heading">Posted Account Activity</h2>
        <div class="official-output-table">
            <table>
                <thead>
                    <tr>
                        <th>Date Posted</th>
                        <th>Entry Type</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($statement['state']['ledger_rows'] as $entry)
                        <tr>
                            <td>{{ $entry['posted_at'] }}</td>
                            <td>{{ $entry['direction'] }}</td>
                            <td>{{ $entry['category'] }}</td>
                            <td>{{ $entry['description'] }}</td>
                            <td>{{ $entry['amount'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5">No posted account activity is recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="finance-summary">
        <div class="official-output-table">
            <table>
                <tbody>
                    <tr><th>Assessment Total</th><td>{{ $statement['state']['assessment_total'] }}</td></tr>
                    <tr><th>Required Downpayment</th><td>{{ $statement['state']['required_downpayment'] }}</td></tr>
                    <tr><th>Posted Payments</th><td>{{ $statement['state']['posted_payments'] }}</td></tr>
                    <tr><th>Current Balance</th><td>{{ $statement['state']['ledger_balance'] }}</td></tr>
                    <tr><th>Payment Status</th><td>{{ $statement['state']['payment_status'] }}</td></tr>
                    <tr><th>Assessment Version</th><td>{{ $statement['state']['soa_version'] }}</td></tr>
                    <tr><th>Verification Status</th><td>{{ $statement['state']['verification_status'] }}</td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <div class="official-output-notice">{{ $statement['disclaimer'] }}</div>
</x-official-output-layout>
