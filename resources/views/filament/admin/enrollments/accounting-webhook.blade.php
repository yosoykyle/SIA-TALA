@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php
    $money = fn ($value) => 'PHP '.number_format((float) $value, 2);
    $referenceReview = in_array($webhookReason, ['missing_tala_reference', 'reference_mismatch'], true);
    $retry = $webhookReason === 'processing_failed';
    $unlinked = $webhookReason === 'unknown_reference';
    $reviewUrl = fn ($eventId, $attemptId = null) => \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'webhook', 'webhook' => $eventId, 'attempt' => $attemptId]);
@endphp
<div class="registrar-case accounting-proof-review">
    <div class="registrar-case-identity"><p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }}</p><p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }}</p><p>{{ $case->term?->label }}</p></div>
    <section class="accounting-proof-task" aria-labelledby="webhook-review-heading">
        <div><h2 id="webhook-review-heading">Review online payment</h2><p>{{ $retry ? 'Processing failed' : ($unlinked ? 'No checkout linked' : ($referenceReview ? 'Payment reference needs review' : 'Payment result needs review')) }} · no verified payment applied</p></div>
        <div class="registrar-case-task-actions">
            @if ($canConfirmWebhook){{ $this->getAction('confirmPayMongoException', isMounting: false) }}@endif
            @if ($canRejectWebhook && ! $unlinked){{ $this->getAction('rejectPayMongoException', isMounting: false)->color('gray') }}@endif
            @if ($canLinkWebhook){{ $this->getAction('linkUnknownPayMongoEvent', isMounting: false) }}@endif
        </div>
    </section>
    @if ($webhookChoices->count() > 1)
        <nav class="accounting-proof-choices" aria-label="Payment results awaiting review">@foreach ($webhookChoices as $choice)<x-filament::link :href="$reviewUrl($choice->id, $attempt?->id)" :aria-current="$choice->id === $webhookEvent->id ? 'page' : null">{{ str(data_get($choice->diagnostics, 'reason', 'Source review'))->replace('_', ' ')->headline() }} · {{ \App\Support\DisplayDateTime::format($choice->occurred_at, 'M j, Y · g:i A') }}</x-filament::link>@endforeach</nav>
    @endif
    <div class="registrar-case-grid">
        <section aria-labelledby="webhook-reported-heading"><h2 id="webhook-reported-heading">Provider-reported payment</h2>
            <p class="accounting-proof-amount">@if (is_int($webhookContext['amount_centavos'] ?? null)){{ $webhookContext['currency'] ?? 'Currency unavailable' }} {{ number_format($webhookContext['amount_centavos'] / 100, 2) }}@else Amount unavailable @endif</p>
            <p>{{ $retry ? 'Reported result · processing did not complete' : 'Unverified claim · no payment applied' }}</p>
            <dl class="registrar-source-charges">
                @if ($attempt)<div><dt>Checkout amount</dt><dd>{{ $money($attempt->amount) }}</dd></div>@foreach ($attempt->obligations as $target)<div><dt>{{ $target->assessmentObligation?->label ?? 'Captured target unavailable' }}</dt><dd>{{ $money($target->amount) }} captured</dd></div>@endforeach<div><dt>TALA reference</dt><dd>{{ $attempt->internal_reference }}</dd></div>@endif
                <div><dt>Reported reference</dt><dd>{{ $webhookContext['tala_reference'] ?? 'Missing' }}</dd></div>
            </dl>
            <details class="registrar-case-reference"><summary>Provider source details</summary><p>Recorded {{ \App\Support\DisplayDateTime::format($webhookEvent->occurred_at, 'M j, Y · g:i A') }}</p><p>{{ $sourceAvailable ? 'Original recorded delivery is available.' : 'Original recorded delivery is missing or conflicts with its recorded source. Accounting must resolve the source before a decision.' }}</p><p>Reported checkout: {{ $webhookContext['checkout_session_id'] ?? 'Unavailable' }}</p></details>
        </section>
        <section aria-labelledby="webhook-next-heading">
            @if ($retry)
                <h2 id="webhook-next-heading">Retry the original recorded delivery</h2><p>No payment decision is available from this failed processing result.</p>
                <details class="registrar-case-reference" open><summary>Recovery details</summary><p>The system administrator must resolve the processing failure. Accounting can retry this original delivery afterward.</p><p>Queued processing rechecks the source and targets. A valid result may record a payment once; conflicting evidence stays in review.</p>
                    @if ($canRetryWebhook)<div class="accounting-webhook-retry"><p>The original failed delivery will be queued for processing again. It does not post a payment by itself.</p>{{ $this->getAction('retryPayMongoEvent', isMounting: false) }}</div>@endif
                </details>
            @elseif ($unlinked)
                <h2 id="webhook-next-heading">Selected checkout in this account</h2>
                @if ($checkoutChoices->count() > 1)<nav class="accounting-proof-choices" aria-label="Account checkouts">@foreach ($checkoutChoices as $choice)<x-filament::link :href="$reviewUrl($webhookEvent->id, $choice->id)" :aria-current="$choice->id === $attempt?->id ? 'page' : null">{{ $choice->internal_reference }} · {{ $money($choice->amount) }}</x-filament::link>@endforeach</nav>@endif
                @if ($attempt)<p>{{ $attempt->internal_reference }} · {{ $money($attempt->amount) }}</p>@else<p>No active PayMongo checkout is available in this account.</p>@endif
                <p>Check the original result against this account and checkout. Linking queues the original delivery; it does not approve a reference discrepancy or confirm payment immediately.</p>
            @elseif ($referenceReview)
                <h2 id="webhook-next-heading">Establish which checkout this result belongs to</h2><p>The reported reference is missing or differs from TALA’s reference. Check the original provider source and selected account before deciding.</p><p>Confirmation remains blocked by other payment or current-source mismatches.</p>
            @else
                <h2 id="webhook-next-heading">The payment mismatch cannot be overridden</h2><p>The reported result conflicts with the captured checkout. Accounting must check the original source; keep the result under review or reject it with a recorded reason.</p>
            @endif
            @if (! $retry && $currentTargets->isNotEmpty())<dl class="registrar-source-charges">@foreach ($currentTargets as $target)<div><dt>{{ $target['label'] }} unpaid now</dt><dd>{{ $money($target['available_balance']) }}</dd></div>@endforeach</dl>@endif
            @if (! $sourceCurrent && $attempt && ! $retry)<p class="accounting-proof-warning">The current assessment or payment targets changed. Resolve the account source before confirming any payment.</p>@endif
        </section>
    </div>
    <div class="accounting-proof-history"><x-filament::link :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history'])" icon="heroicon-o-arrow-right" icon-position="after">Account history</x-filament::link></div>
</div>
