@php
    $money = fn ($value) => 'PHP '.number_format((float) $value, 2);
@endphp
@if ($proof)
    <div class="accounting-proof-context">
        <p><strong>{{ $case->credentialUser?->getFilamentName() }}</strong> · Proof version {{ $proof->version }}</p>
        <p>{{ $money($proof->claimed_amount) }} claimed · {{ str($proof->channel)->headline() }} · {{ $proof->payment_reference ?: 'No payment reference supplied' }}</p>
        <p>Paid {{ \App\Support\DisplayDateTime::format($proof->paid_at, 'M j, Y · g:i A') }}</p>
        <p>Targets: {{ collect($proof->obligation_snapshot)->pluck('label')->join(', ') }}</p>
        @if ($proof->possible_duplicate)
            <p class="accounting-proof-warning">Possible duplicate receipt. Check the existing payment history before recording a payment.</p>
        @endif
    </div>
@else
    <p>This proof is no longer awaiting review. Close this form and refresh the account.</p>
@endif
