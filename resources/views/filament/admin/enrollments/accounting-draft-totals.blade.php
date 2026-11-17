@php
    $balanced = $chargeTotal !== null && $paymentTotal !== null && $chargeTotal === $paymentTotal;
    $unbalanced = $chargeTotal !== null && $paymentTotal !== null && ! $balanced;
@endphp
<div class="tala-live-totals accounting-fee-plan-form-totals" role="status" aria-live="polite">
    <dl>
        <div>
            <dt>Total charges</dt>
            <dd>{{ $chargeTotal === null ? 'Enter amounts' : \App\Actions\Finance\AccountingDisplay::cents($chargeTotal) }}</dd>
        </div>
        <div>
            <dt>Total payments</dt>
            <dd>{{ $paymentTotal === null ? 'Enter amounts' : \App\Actions\Finance\AccountingDisplay::cents($paymentTotal) }}</dd>
        </div>
    </dl>
    @if ($chargeTotal !== null && $paymentTotal !== null)
        <p @class([
            'accounting-fee-plan-form-totals__status',
            'is-balanced' => $balanced,
            'is-unbalanced' => $unbalanced,
        ])>
            <x-filament::icon :icon="$balanced ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'" aria-hidden="true" />
            <span>{{ $balanced ? 'Amounts match' : 'Charges and payment dates must add up to the same total. Difference: '.\App\Actions\Finance\AccountingDisplay::cents(abs($chargeTotal - $paymentTotal)) }}</span>
        </p>
    @endif
</div>
