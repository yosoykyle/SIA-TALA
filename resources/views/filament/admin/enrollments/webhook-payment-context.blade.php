<div class="accounting-proof-context">
    <p><strong>{{ $case->credentialUser?->getFilamentName() }}</strong></p>
    <p>@if (is_int($webhookContext['amount_centavos'] ?? null)){{ $webhookContext['currency'] ?? 'Currency unavailable' }} {{ number_format($webhookContext['amount_centavos'] / 100, 2) }} reported @else Reported amount unavailable @endif
        @if ($attempt) · PHP {{ number_format((float) $attempt->amount, 2) }} captured @endif</p>
    @if ($attempt)<p>{{ $attempt->internal_reference }}</p><p>{{ $attempt->obligations->map(fn ($target) => $target->assessmentObligation?->label)->filter()->join(', ') }}</p>@endif
</div>
@if (($showEffect ?? false) && $canConfirmWebhook)
    <div class="accounting-proof-allocation"><h3>Payment effect</h3>
        @foreach ($attempt->obligations as $target)
            @php($current = $currentTargets->firstWhere('id', $target->assessment_obligation_id))
            <div><strong>{{ $target->assessmentObligation?->label }}</strong><p>PHP {{ number_format((float) $target->amount, 2) }} applied · PHP {{ number_format((float) app(\App\Support\DecimalMoney::class)->subtract($current['available_balance'], $target->amount), 2) }} remaining</p></div>
        @endforeach
    </div>
@endif
