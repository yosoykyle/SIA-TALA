@if ($proof)
    <div class="tala-context-inset accounting-proof-context">
        <p><strong>{{ \App\Actions\Finance\AccountingDisplay::learnerName($case) }}</strong> · Receipt version {{ $proof->version }}</p>
        <p>{{ \App\Actions\Finance\AccountingDisplay::money($proof->claimed_amount) }} claimed · {{ \App\Actions\Finance\AccountingDisplay::channelDetailLabel($proof->channel, $proof->channel_snapshot) }} · {{ $proof->payment_reference ?: 'No payment reference supplied' }}</p>
        <p>Paid {{ \App\Actions\Finance\AccountingDisplay::dateTime($proof->paid_at) }}</p>
        <p>Targets: {{ collect($proof->obligation_snapshot)->pluck('label')->join(', ') }}</p>
        @if ($proof->possible_duplicate)
            <p class="tala-warning-banner accounting-proof-warning" role="status">Possible duplicate receipt. Check the existing payment history before recording a payment.</p>
        @endif
    </div>
@else
    <p class="tala-warning-banner accounting-proof-warning" role="status">This receipt is no longer awaiting review. Close this form and refresh the account.</p>
@endif
