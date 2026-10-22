<div class="student-finance student-finance-past student-finance-history">
    <x-filament::link :href="static::getUrl($pastAccount ? ['view' => 'history'] : [])">{{ $pastAccount ? 'Back to past statements and payments' : 'Back to fees and payments' }}</x-filament::link>
    @if (! $pastAccount)
        <h2>Past statements and payments</h2>
        <p>Choose a term to find its statements, payments and submitted receipts.</p>
        <ul class="student-finance-past-list">
            @forelse ($pastAccounts as $record)
                <li><h3>{{ $record->term?->label ?? 'Term not recorded' }}</h3><x-filament::button tag="a" color="gray" :href="static::getUrl(['view' => 'history', 'account' => $record->id])">View records<span class="sr-only">: {{ $record->term?->label }}</span></x-filament::button></li>
            @empty
                <li>No earlier term accounts are recorded.</li>
            @endforelse
        </ul>
    @else
        @php
            $statementAssessment = $pastAccount->assessments->firstWhere('state', \App\Models\Assessment::StateActive) ?? $pastAccount->assessments->first();
            $earlierAssessments = $pastAccount->assessments->reject(fn ($assessment) => $assessment->is($statementAssessment));
        @endphp
        <header class="student-finance-history-heading">
            <div><h2>{{ $pastAccount->term?->label ?? 'Term not recorded' }}</h2><p>Past payments and documents for this term.</p></div>
            @if ($statementAssessment)
                <x-filament::button tag="a" color="gray" :href="route('finance.statement', $statementAssessment)" target="_blank" rel="noopener">View statement</x-filament::button>
            @endif
        </header>
        @if (! $statementAssessment)<p>No statement is available for this term. Contact Accounting if you need this document.</p>@endif
        <div class="student-finance-history-layout">
        <div class="student-finance-history-activity">
        <section aria-labelledby="past-payments"><h3 id="past-payments">Payments</h3>
            @forelse ($pastAccount->payments as $payment)
                <article class="student-finance-past-record">
                    <div class="student-finance-history-record-heading"><h4>{{ $payment->state === \App\Models\Payment::StatePosted ? 'Verified payment' : 'Payment reversal' }}</h4><strong>{{ $money($payment->amount) }}</strong></div>
                    <p>{{ $payment->channelLabel() }} · {{ $date($payment->state === \App\Models\Payment::StateReversal ? ($payment->external_refund_at ?? $payment->paid_at) : $payment->paid_at) }}</p>
                    @if ($payment->state === \App\Models\Payment::StatePosted)<x-filament::link :href="route('finance.payments.acknowledgement', $payment)" target="_blank" rel="noopener">View payment record</x-filament::link>@endif
                </article>
            @empty<p>No verified payment is recorded for this term.</p>@endforelse
        </section>
        @if ($pastAccount->paymentEvidenceVersions->isNotEmpty())
        <section aria-labelledby="past-proof"><h3 id="past-proof">Submitted receipts</h3>
            <p class="student-finance-secondary">Receipt submissions are separate from verified payments.</p>
            @foreach ($pastAccount->paymentEvidenceVersions as $proof)
                <article class="student-finance-past-record student-finance-history-receipt"><div><h4>{{ $proof->original_name }}</h4><p>Amount on receipt: {{ $money($proof->claimed_amount) }} · {{ str($proof->state)->headline() }}</p><p>Submitted {{ $date($proof->submitted_at) }}</p>@if ($proof->review_note)<p>{{ $proof->review_note }}</p><p class="student-finance-secondary">Reviewed {{ $date($proof->reviewed_at) }}</p>@endif</div><x-payment-proof-viewer :proof="$proof" label="View receipt" /></article>
            @endforeach
        </section>
        @endif
        @if ($earlierAssessments->isNotEmpty())
            <details class="learner-disclosure"><summary>Earlier statements</summary><div>
                @foreach ($earlierAssessments as $assessment)<p><x-filament::link :href="route('finance.statement', $assessment)" target="_blank" rel="noopener">Statement{{ $assessment->created_at ? ' · '.$date($assessment->created_at) : '' }} · {{ $money($assessment->total) }}</x-filament::link></p>@endforeach
            </div></details>
        @endif
        </div>
        @if ($pastAccount->coverages->isNotEmpty())
        <aside class="student-finance-history-assistance" aria-labelledby="past-sources"><h3 id="past-sources">Fee assistance</h3>
            @foreach ($pastAccount->coverages as $coverage)
                <article class="student-finance-past-record"><h4>{{ $coverage->safe_source_description }} · {{ $money($coverage->amount) }}</h4><p>{{ str($coverage->state)->headline() }}@if ($coverage->effective_date) · {{ $coverage->effective_date->format('M j, Y') }}@endif</p>@if ($coverage->reversal_reason)<p>{{ $coverage->reversal_reason }}</p>@endif</article>
            @endforeach
        </aside>
        @endif
        </div>
    @endif
</div>
