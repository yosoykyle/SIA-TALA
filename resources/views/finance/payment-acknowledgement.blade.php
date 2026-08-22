<x-official-output-layout
    title="Payment Acknowledgment"
    context="Verified TALA Posting"
    :subtitle="$acknowledgement['term']"
    :generated-at="\App\Support\DisplayDateTime::format($acknowledgement['generated_at'], 'M d, Y h:i A')"
>
    <div class="official-output-table"><table><tbody>
        <tr><th>Learner</th><td>{{ $acknowledgement['owner'] }}</td></tr>
        <tr><th>Term Account</th><td>TERM-ACCOUNT-{{ $acknowledgement['payment']->term_account_id }}</td></tr>
        <tr><th>Payment Reference</th><td>{{ $acknowledgement['payment']->provider_reference }}</td></tr>
        <tr><th>Channel</th><td>{{ $acknowledgement['payment']->channelLabel() }}</td></tr>
        <tr><th>Actual Verified Amount</th><td>PHP {{ number_format((float) $acknowledgement['payment']->amount, 2) }}</td></tr>
        <tr><th>Paid</th><td>{{ \App\Support\DisplayDateTime::format($acknowledgement['payment']->paid_at, 'M d, Y h:i A') }}</td></tr>
        <tr><th>Verified</th><td>{{ \App\Support\DisplayDateTime::format($acknowledgement['payment']->verified_at, 'M d, Y h:i A') }}</td></tr>
        <tr><th>Posting State</th><td>{{ $acknowledgement['payment']->state }}</td></tr>
    </tbody></table></div>

    <h2>Obligation Effects</h2>
    <div class="official-output-table"><table>
        <thead><tr><th>Applied To</th><th>Amount</th></tr></thead>
        <tbody>@foreach ($acknowledgement['allocations'] as $allocation)
            <tr><td>{{ $allocation['target'] }}</td><td>PHP {{ number_format((float) $allocation['amount'], 2) }}</td></tr>
        @endforeach</tbody>
    </table></div>

    <div class="official-output-notice">{{ $acknowledgement['disclaimer'] }}</div>
</x-official-output-layout>
