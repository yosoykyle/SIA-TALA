<div class="student-checkout-confirmation">
    <p class="student-checkout-amount">{{ $checkout['amount'] === null ? 'Amount unavailable' : 'PHP '.number_format((float) $checkout['amount'], 2) }}</p>
    <dl class="learner-money student-checkout-targets" aria-label="Fees to pay">
        @foreach ($checkout['obligations'] as $target)
            <div><dt>{{ $target['label'] }}</dt><dd>PHP {{ number_format((float) $target['amount'], 2) }}</dd></div>
        @endforeach
    </dl>
    <p>You will continue to PayMongo to pay these fees. Your balance updates after payment is verified.</p>
</div>
