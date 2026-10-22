@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php
    $money = fn ($value) => 'PHP '.number_format((float) $value, 2);
    $historyUrl = \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history']);
@endphp
<div class="registrar-case accounting-proof-review">
    <div class="registrar-case-identity">
        <p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }}</p>
        <p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <section class="accounting-proof-task" aria-labelledby="proof-review-heading">
        <div><h2 id="proof-review-heading">Review payment proof</h2>
            <p>Submitted {{ \App\Support\DisplayDateTime::format($proof->submitted_at, 'M j, Y · g:i A') }}</p>
        </div>
        <div class="registrar-case-task-actions">
            {{ $this->getAction('verifyPaymentEvidence', isMounting: false) }}
            {{ $this->getAction('rejectPaymentEvidence', isMounting: false)->color('gray') }}
        </div>
    </section>
    @if ($pendingProofs->count() > 1)
        <nav class="accounting-proof-choices" aria-label="Submitted payment proofs">
            @foreach ($pendingProofs as $pending)
                <x-filament::link :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'proof', 'proof' => $pending->id])" :aria-current="$pending->id === $proof->id ? 'page' : null">{{ $pending->original_name }} · {{ $money($pending->claimed_amount) }} · {{ \App\Support\DisplayDateTime::format($pending->submitted_at, 'M j, Y · g:i A') }}</x-filament::link>
            @endforeach
        </nav>
    @endif
    <div class="registrar-case-grid">
        <section aria-labelledby="proof-submitted-heading">
            <h2 id="proof-submitted-heading">Submitted receipt</h2>
            <p class="accounting-proof-amount">{{ $money($proof->claimed_amount) }} <span>claimed</span></p>
            <dl class="registrar-source-charges">
                <div><dt>Payment channel</dt><dd>{{ str($proof->channel)->headline() }}</dd></div>
                <div><dt>Paid on</dt><dd>{{ \App\Support\DisplayDateTime::format($proof->paid_at, 'M j, Y · g:i A') }}</dd></div>
                @if ($proof->payment_reference)<div><dt>Payment reference</dt><dd>{{ $proof->payment_reference }}</dd></div>@endif
            </dl>
            <div class="accounting-context-actions"><x-payment-proof-viewer :proof="$proof" label="View submitted proof" /><p>{{ $proof->original_name }} · {{ str($proof->mime_type)->after('/')->upper() }}</p></div>
            @if ($proof->possible_duplicate)<p class="accounting-proof-warning">Possible duplicate receipt. Check payment history before recording a payment.</p>@endif
        </section>
        <section aria-labelledby="proof-targets-heading">
            <h2 id="proof-targets-heading">Payment targets</h2>
            @if ($sourceCurrent)
                <p>Current unpaid balance of the targets captured with this proof.</p>
                <dl class="registrar-source-charges">
                    @foreach ($targets as $target)<div><dt>{{ $target['label'] }}</dt><dd>{{ $money($target['available_balance']) }}</dd></div>@endforeach
                </dl>
            @else
                <p>The captured assessment or payment targets changed. Review the account before recording a payment.</p>
                <p>Submitted for {{ collect($proof->obligation_snapshot)->pluck('label')->join(', ') }}.</p>
            @endif
            <details class="registrar-case-reference"><summary>Submission source</summary><p>Proof version {{ $proof->version }} · Assessment {{ $proof->assessment_id }}</p></details>
        </section>
    </div>
    <div class="accounting-proof-history"><x-filament::link :href="$historyUrl" icon="heroicon-o-arrow-right" icon-position="after">Account history</x-filament::link></div>
</div>
