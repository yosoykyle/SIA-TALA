<div class="accounting-fee-plan-form-totals" role="status" aria-live="polite">
    <dl><div><dt>Total charges</dt><dd>{{ $chargeTotal === null ? 'Enter amounts' : 'PHP '.number_format($chargeTotal / 100, 2) }}</dd></div><div><dt>Scheduled payments</dt><dd>{{ $paymentTotal === null ? 'Enter amounts' : 'PHP '.number_format($paymentTotal / 100, 2) }}</dd></div></dl>
    @if ($chargeTotal !== null && $paymentTotal !== null)<p>{{ $chargeTotal === $paymentTotal ? 'Amounts match' : 'Payments must match the total charges. Difference: PHP '.number_format(abs($chargeTotal - $paymentTotal) / 100, 2) }}</p>@endif
</div>
