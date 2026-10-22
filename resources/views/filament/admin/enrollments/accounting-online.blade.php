@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php
    $money = fn ($value) => 'PHP '.number_format((float) $value, 2);
    $historyUrl = \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history']);
@endphp
<div class="registrar-case accounting-proof-review accounting-online-review">
    <div class="registrar-case-identity"><p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }}</p><p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }}</p><p>{{ $case->term?->label }}</p></div>
    <section class="accounting-proof-task" aria-labelledby="online-payment-heading">
        <div><h2 id="online-payment-heading">{{ $onlineEvent ? 'Review recovered payment' : ($canRetrieve ? 'Check online payment' : 'Online payment needs source review') }}</h2>
            <p>{{ $onlineEvent ? 'Provider result awaits Accounting confirmation' : ($canRetrieve ? str($attempt->status)->headline().' checkout · no verified payment recorded' : 'Accounting review required · no verified payment recorded') }}</p></div>
        <div class="registrar-case-task-actions">
            @if ($onlineEvent)
                {{ $this->getAction('confirmRecoveredPayMongo', isMounting: false) }}
                {{ $this->getAction('rejectRecoveredPayMongo', isMounting: false)->color('gray') }}
            @elseif ($canRetrieve)
                {{ $this->getAction('retrievePayMongoCheckout', isMounting: false) }}
            @endif
        </div>
    </section>
    @if ($onlineAttempts->count() > 1 || $onlineEvents->count() > 1)
        <nav class="accounting-proof-choices" aria-label="Online payment review choices">
            @foreach ($onlineAttempts as $choice)<x-filament::link :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'online', 'attempt' => $choice->id])" :aria-current="$choice->id === $attempt->id ? 'page' : null">{{ $money($choice->amount) }} · {{ str($choice->status)->headline() }} · {{ \App\Support\DisplayDateTime::format($choice->created_at, 'M j, Y · g:i A') }}</x-filament::link>@endforeach
            @if ($onlineEvents->count() > 1)
                @foreach ($onlineEvents as $choice)<x-filament::link :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'online', 'attempt' => $attempt->id, 'event' => $choice->id])" :aria-current="$choice->id === $onlineEvent?->id ? 'page' : null">Provider result · {{ \App\Support\DisplayDateTime::format($choice->occurred_at, 'M j, Y · g:i A') }}</x-filament::link>@endforeach
            @endif
        </nav>
    @endif
    <div class="registrar-case-grid">
        <section aria-labelledby="online-captured-heading">
            <h2 id="online-captured-heading">{{ $onlineEvent ? 'Provider-reported payment' : 'Checkout amount' }}</h2>
            <p class="accounting-proof-amount">@if ($onlineEvent){{ is_int($providerPayment['amount_centavos'] ?? null) ? (($providerPayment['currency'] ?? 'Currency unavailable').' '.number_format($providerPayment['amount_centavos'] / 100, 2)) : 'Amount unavailable' }}@else{{ $money($attempt->amount) }}@endif</p>
            <p>{{ $onlineEvent ? 'Reported '.str($providerPayment['status'] ?? 'status unavailable')->lower() : 'Not recorded as payment' }}</p>
            <dl class="registrar-source-charges"><div><dt>Reference</dt><dd>{{ $attempt->internal_reference }}</dd></div>
                @foreach ($attempt->obligations as $target)<div><dt>{{ $target->assessmentObligation?->label ?? 'Captured target unavailable' }}</dt><dd>{{ $money($target->amount) }} captured</dd></div>@endforeach
            </dl>
            <details class="registrar-case-reference"><summary>Payment source details</summary>
                <p>Assessment version {{ $attempt->assessment_version }} · {{ \App\Support\DisplayDateTime::format($attempt->created_at, 'M j, Y · g:i A') }}</p>
                <p>{{ filled($attempt->provider_checkout_id) ? 'Provider checkout: '.$attempt->provider_checkout_id : 'No provider checkout reference recorded.' }}</p>
                @if ($onlineEvent)<p>Result received {{ \App\Support\DisplayDateTime::format($onlineEvent->occurred_at, 'M j, Y · g:i A') }} · {{ $providerPayment['id'] ?? 'Payment reference unavailable' }}</p>@endif
            </details>
        </section>
        <section aria-labelledby="online-next-heading">
            @if ($onlineEvent)
                <h2 id="online-next-heading">Compare with the captured target</h2>
                <p>No verified payment has been posted for this result. Check the exact provider result and current account before confirming.</p>
                @if (! $resultMatches)<p class="accounting-proof-warning">The provider result does not exactly match this checkout. Confirmation is unavailable; review the source or reject the result.</p>@endif
                @if (! $sourceCurrent)<p class="accounting-proof-warning">The current assessment or unpaid targets changed. Resolve the account source before confirming this payment.</p>@endif
                @if ($currentTargets->isNotEmpty())<dl class="registrar-source-charges">@foreach ($currentTargets as $target)<div><dt>{{ $target['label'] }} unpaid now</dt><dd>{{ $money($target['available_balance']) }}</dd></div>@endforeach</dl>@endif
            @elseif ($canRetrieve)
                <h2 id="online-next-heading">Accounting can check this checkout</h2><p>Retrieve the latest provider result for this checkout. Checking status does not record a payment.</p><p>If a paid result is found, review it before confirming payment.</p>
            @else
                <h2 id="online-next-heading">Payment evidence is missing</h2><p>This attempt has no usable checkout or provider payment result for this review.</p><div class="accounting-proof-warning"><strong>Accounting owns the next step</strong><p>Check the original payment source with the learner. Keep the payment under review until reliable checkout or payment evidence is available.</p></div>
            @endif
        </section>
    </div>
    <div class="accounting-proof-history"><x-filament::link :href="$historyUrl" icon="heroicon-o-arrow-right" icon-position="after">Account history</x-filament::link></div>
</div>
