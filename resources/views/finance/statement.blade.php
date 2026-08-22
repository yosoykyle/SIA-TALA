<x-official-output-layout
    title="Statement of Account"
    :context="$statement['is_historical'] ? 'Historical Assessment' : 'Authenticated Account Copy'"
    :subtitle="$statement['term']"
    :generated-at="\App\Support\DisplayDateTime::format($statement['generated_at'], 'M d, Y h:i A')"
>
    <section class="finance-grid">
        <div><strong>Account:</strong> TERM-ACCOUNT-{{ $statement['account']->id }}</div>
        <div><strong>Learner:</strong> {{ $statement['owner'] }}</div>
        <div><strong>Program:</strong> {{ $statement['program'] }}</div>
        <div><strong>Term:</strong> {{ $statement['term'] }}</div>
        <div><strong>Assessment Version:</strong> {{ $statement['assessment']->version }}</div>
        <div><strong>Assessment Authority:</strong> {{ $statement['authority_reference'] }}</div>
    </section>

    <section>
        <h2 class="finance-heading">Dated Obligations</h2>
        <div class="official-output-table finance-responsive-table">
            <table>
                <thead><tr><th>Obligation</th><th>Purpose</th><th>Due</th><th>Original</th><th>Balance</th><th>Status</th></tr></thead>
                <tbody>
                    @foreach ($statement['position']['obligations'] as $obligation)
                        <tr>
                            <td data-label="Obligation">{{ $obligation['label'] }}</td>
                            <td data-label="Purpose">{{ $obligation['purpose'] }}</td>
                            <td data-label="Due">{{ \App\Support\DisplayDateTime::format(\Carbon\CarbonImmutable::parse($obligation['due_at']), 'M d, Y h:i A') }}</td>
                            <td data-label="Original">PHP {{ number_format((float) $obligation['amount'], 2) }}</td>
                            <td data-label="Balance">PHP {{ number_format((float) $obligation['balance'], 2) }}</td>
                            <td data-label="Status">{{ $obligation['balance'] === '0.00' ? 'Satisfied' : ($obligation['is_due'] ? 'Due' : 'Later') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="finance-summary">
        <div class="official-output-table"><table><tbody>
            <tr><th>Due Through As-of</th><td>PHP {{ number_format((float) $statement['position']['current_due'], 2) }}</td></tr>
            <tr><th>Remaining Term Balance</th><td>PHP {{ number_format((float) $statement['position']['remaining_balance'], 2) }}</td></tr>
            <tr><th>Verified Payments Applied</th><td>PHP {{ number_format((float) $statement['position']['payment_applied'], 2) }}</td></tr>
            <tr><th>Approved Coverage Applied</th><td>PHP {{ number_format((float) $statement['position']['coverage_applied'], 2) }}</td></tr>
        </tbody></table></div>
    </section>

    <div class="official-output-notice">{{ $statement['disclaimer'] }}</div>
</x-official-output-layout>
