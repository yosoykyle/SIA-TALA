@php
    $balanced = $chargeTotal !== null && $paymentTotal !== null && $chargeTotal === $paymentTotal;
    $unbalanced = $chargeTotal !== null && $paymentTotal !== null && ! $balanced;
@endphp
<div @class([
    'tala-live-totals',
    'accounting-fee-plan-form-totals',
    'is-balanced' => $balanced,
    'is-unbalanced' => $unbalanced,
]) role="status" aria-live="polite">
    <dl>
        <div>
            <dt>Total charges</dt>
            <dd @class(['is-empty' => $chargeTotal === null])>{{ $chargeTotal === null ? 'Enter amounts' : \App\Actions\Finance\AccountingDisplay::cents($chargeTotal) }}</dd>
        </div>
        <div>
            <dt>Scheduled payments</dt>
            <dd @class(['is-empty' => $paymentTotal === null])>{{ $paymentTotal === null ? 'Enter amounts' : \App\Actions\Finance\AccountingDisplay::cents($paymentTotal) }}</dd>
        </div>
    </dl>
    @if ($chargeTotal !== null && $paymentTotal !== null)
        <p @class([
            'accounting-fee-plan-form-totals__status',
            'is-balanced' => $balanced,
            'is-unbalanced' => $unbalanced,
        ])>
            <x-filament::icon :icon="$balanced ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'" aria-hidden="true" />
            <span>{{ $balanced ? 'Amounts match' : 'Payments must match the total charges. Difference: '.\App\Actions\Finance\AccountingDisplay::cents(abs($chargeTotal - $paymentTotal)) }}</span>
        </p>
    @endif
</div>
