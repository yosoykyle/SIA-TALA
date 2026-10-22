<div class="accounting-proof-context">
    <p><strong>{{ $case->credentialUser?->getFilamentName() }}</strong></p>
    <p>PHP {{ number_format((float) $attempt?->amount, 2) }} · {{ $attempt?->obligations->map(fn ($target) => $target->assessmentObligation?->label)->filter()->join(', ') }}</p>
    <p>{{ $attempt?->internal_reference }}</p>
    @if ($onlineEvent)
        <p>Provider result: {{ str($providerPayment['status'] ?? 'Unavailable')->headline() }} · {{ $providerPayment['currency'] ?? 'Currency unavailable' }}
            @if (is_int($providerPayment['amount_centavos'] ?? null)){{ number_format($providerPayment['amount_centavos'] / 100, 2) }}@else Amount unavailable @endif
        </p>
    @endif
</div>
@if ($showEffect ?? false)
    <div class="accounting-proof-allocation"><h3>Payment effect</h3>
        @if ($canConfirm)
            @foreach ($attempt->obligations as $target)
                @php($current = $currentTargets->firstWhere('id', $target->assessment_obligation_id))
                <div><strong>{{ $target->assessmentObligation?->label }}</strong><p>PHP {{ number_format((float) $target->amount, 2) }} applied · PHP {{ number_format((float) app(\App\Support\DecimalMoney::class)->subtract($current['available_balance'], $target->amount), 2) }} remaining</p></div>
            @endforeach
        @else
            <p>The current account or provider result does not match the captured payment. Refresh and resolve the source before confirming.</p>
        @endif
    </div>
@endif
