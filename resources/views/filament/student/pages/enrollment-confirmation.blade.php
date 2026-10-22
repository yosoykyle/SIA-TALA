@php($money = fn ($value) => $value === null ? 'Unavailable' : 'PHP '.number_format((float) $value, 2))
<div class="student-registration-details">
    <p>{{ $enrollment?->term?->label }}</p>
    <p>{{ $proposal?->items->count() }} {{ $proposal?->items->count() === 1 ? 'subject' : 'subjects' }} · {{ (float) $proposal?->items->sum('units_snapshot') }} units</p>
    <dl class="learner-money">
        <div><dt>Enrollment payment remaining</dt><dd>{{ $money($paymentRequirement['balance'] ?? null) }}</dd></div>
        <div><dt>Total term fees</dt><dd>{{ $money($assessment?->total) }}</dd></div>
    </dl>
    <p>{{ ($paymentRequirement['state'] ?? '') === 'Cleared' ? 'The enrollment payment requirement is met.' : 'Payment follows confirmation.' }} Contact the Registrar to change confirmed subjects.</p>
</div>
