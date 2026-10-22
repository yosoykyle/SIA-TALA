@php
    $available = ($accountData['available'] ?? false) && ($position['state'] ?? 'Unavailable') !== 'Unavailable';
    $due = $available && (float) $position['current_due'] > 0;
    $latestPending = $latestEvidence?->state === \App\Models\PaymentEvidenceVersion::StateSubmitted;
    $latestRejected = $latestEvidence?->state === \App\Models\PaymentEvidenceVersion::StateRejected && $due;
    $later = $available ? collect($position['obligations'])->where('is_due', false)->sum('balance') : null;
@endphp
<div class="student-finance student-finance-workspace">
    <p class="student-information-intro">{{ data_get($accountData, 'state.term') }}</p>
    <div wire:loading.delay wire:target="refreshFinance" role="status">Refreshing your account…</div>
    @if (! $available)
        <section class="student-finance-task tala-task-surface" aria-labelledby="finance-unavailable"><h2 id="finance-unavailable">Account information unavailable</h2><p>Your fees or payment records need an Accounting review. Contact Accounting before making a payment.</p><x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray">Refresh account</x-filament::button></section>
    @else
        <div class="student-finance-board">
            <section class="student-finance-task tala-task-surface {{ $latestRejected || $activeAttempt ? 'student-finance-task-review' : '' }}" aria-labelledby="finance-task-heading">
                @if ($activeAttempt)
                    <h2 id="finance-task-heading">{{ $activeAttempt->status === \App\Models\PaymentAttempt::StatusReviewRequired ? 'Online payment needs Accounting review' : 'Payment confirmation pending' }}</h2>
                    <p>{{ $activeAttempt->status === \App\Models\PaymentAttempt::StatusReviewRequired ? 'Accounting is checking this online payment. Wait for their review before paying again.' : 'We are waiting for confirmation of this online payment. Refresh the status after completing payment with the provider.' }}</p><p>Online payment amount {{ $money($activeAttempt->amount) }}. Your balance updates after verification.</p>
                    <div class="student-finance-actions"><x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" icon="heroicon-o-arrow-path">Refresh status</x-filament::button>{{ $this->getAction('submitEvidence', isMounting: false)->color('gray') }}</div>
                @elseif ($latestPending)
                    <h2 id="finance-task-heading">Payment proof awaiting review</h2><p>Accounting will review your {{ $money($latestEvidence->claimed_amount) }} payment proof before your balance changes.</p>
                    <x-workflow-handoff current-owner="Accounting" current-task="Review payment proof" next-owner="You" next-task="View updated account" current-icon="heroicon-o-banknotes" next-icon="heroicon-o-document-text" />
                    <p>{{ $latestEvidence->original_name }}<span class="student-finance-secondary">Submitted {{ $date($latestEvidence->submitted_at) }}</span></p>
                    <div class="student-finance-actions"><x-payment-proof-viewer :proof="$latestEvidence" label="View submitted proof" primary /><x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Refresh status</x-filament::button></div>
                @elseif ($latestRejected)
                    <h2 id="finance-task-heading">Payment proof needs correction</h2><p>{{ $latestEvidence->review_note ?: 'Contact Accounting to find out what needs to be corrected.' }}</p><p>{{ $latestEvidence->original_name }} · {{ $money($latestEvidence->claimed_amount) }} submitted<span class="student-finance-secondary">Reviewed {{ $date($latestEvidence->reviewed_at) }}</span></p>
                    <div class="student-finance-actions">{{ $this->getAction('submitEvidence', isMounting: false) }}<x-payment-proof-viewer :proof="$latestEvidence" label="View previous proof" /></div>
                @elseif ($due)
                    <h2 id="finance-task-heading">Your next payment</h2><p>If you have already paid, submit your proof for Accounting to review.</p>
                    <div class="student-finance-actions">{{ $this->getAction('submitEvidence', isMounting: false) }}@if (data_get($accountData, 'checkout.enabled')){{ $this->getAction('payExactCurrentDue', isMounting: false)->color('gray') }}@endif</div>
                    @if (! data_get($accountData, 'checkout.enabled'))<p class="student-finance-secondary">{{ data_get($accountData, 'checkout.reason') }}</p>@endif
                @else
                    <h2 class="student-record-heading" id="finance-task-heading"><x-filament::icon icon="heroicon-o-check-circle" />No payment due today</h2>
                    @if ($position['next_obligation'])<p class="student-finance-next-label">Next payment</p><p class="student-finance-next-amount">{{ $money($position['next_obligation']['balance']) }}</p><p class="student-finance-secondary">Due {{ $date($position['next_obligation']['due_at']) }}</p>@else<p>No payments remain for this term.</p>@endif
                    <x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Refresh account</x-filament::button>
                @endif
            </section>
            <section class="student-finance-position tala-task-surface" aria-labelledby="finance-position-heading"><h2 id="finance-position-heading">Account balance</h2><dl><div><dt>Amount due now</dt><dd>{{ $money($position['current_due']) }}</dd></div><div><dt>Later fees</dt><dd>{{ $money($later) }}</dd></div><div><dt>Remaining term balance</dt><dd>{{ $money($position['remaining_balance']) }}</dd></div></dl><div class="student-finance-actions">{{ $this->getAction('statement', isMounting: false) }}</div></section>
        </div>
        <section class="student-finance-section tala-task-surface" aria-labelledby="payment-schedule-heading"><h2 class="student-record-heading" id="payment-schedule-heading"><x-filament::icon icon="heroicon-o-calendar-days" />Payment schedule</h2><ul class="student-payment-schedule">
            @foreach ($position['obligations'] as $obligation)<li wire:key="account-obligation-{{ $obligation['id'] }}"><div><h3>{{ $obligation['label'] }}</h3><p class="student-finance-secondary">Due {{ $date($obligation['due_at']) }}</p>@if ((float) $obligation['payment_applied'] > 0)<p class="student-finance-secondary">{{ $money($obligation['payment_applied']) }} verified payment</p>@endif @if ((float) $obligation['coverage_applied'] > 0)<p class="student-finance-secondary">{{ $money($obligation['coverage_applied']) }} approved fee assistance</p>@endif</div><div class="student-finance-number"><strong>{{ $money($obligation['amount']) }}</strong>@if ((float) $obligation['balance'] > 0 && (float) $obligation['balance'] < (float) $obligation['amount'])<span class="student-finance-secondary">{{ $money($obligation['balance']) }} remaining</span>@endif</div><x-filament::badge :color="$obligation['balance'] === '0.00' ? 'success' : ($obligation['is_due'] ? 'warning' : 'gray')">{{ $obligation['balance'] === '0.00' ? 'Satisfied' : ($obligation['is_due'] ? 'Due' : 'Upcoming') }}</x-filament::badge></li>@endforeach
        </ul></section>
    @endif
    <section class="student-finance-section tala-task-surface" aria-labelledby="payments-heading"><h2 id="payments-heading">Payments</h2>
        @if ($payments->isEmpty())<p class="student-finance-secondary">No verified payment is recorded for this term. Submitted proof does not change your balance.</p>
        @else<div class="student-finance-table-wrap" tabindex="0" role="region" aria-label="Recorded payments"><table class="student-finance-table"><thead><tr><th scope="col">Date</th><th scope="col">Method</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Documents</th></tr></thead><tbody>
            @foreach ($payments->sortByDesc('paid_at') as $payment)<tr wire:key="finance-payment-{{ $payment->id }}"><td>{{ $date($payment->state === \App\Models\Payment::StateReversal ? ($payment->external_refund_at ?? $payment->paid_at) : $payment->paid_at) }}</td><td>{{ $payment->channelLabel() }}</td><td class="student-finance-number">{{ $money($payment->amount) }}</td><td>{{ $payment->state === \App\Models\Payment::StatePosted ? 'Verified' : 'Reversal' }}</td><td>@if ($payment->state === \App\Models\Payment::StatePosted)<x-filament::link :href="route('finance.payments.acknowledgement', $payment)" target="_blank" rel="noopener">View acknowledgment</x-filament::link>@endif</td></tr>@endforeach
        </tbody></table></div>@endif
    </section>
    @php($otherReceipts = $evidence->reject(fn ($item) => $available && ! $activeAttempt && $item->is($latestEvidence) && ($latestPending || $latestRejected)))
    @if ($otherReceipts->isNotEmpty())
        <section class="student-finance-records tala-task-surface" aria-labelledby="finance-receipts-heading"><h2 id="finance-receipts-heading">Submitted receipts</h2>
            @foreach ($otherReceipts as $item)<article class="student-receipt-row" wire:key="finance-evidence-{{ $item->id }}"><div><h3>{{ $item->original_name }}</h3><p>{{ $money($item->claimed_amount) }} · {{ str($item->state)->headline() }} · {{ $date($item->submitted_at) }}</p>@if ($item->review_note)<p>{{ $item->review_note }}</p>@endif</div><x-payment-proof-viewer :proof="$item" /></article>@endforeach
        </section>
    @endif
    @if ($assessmentLines->isNotEmpty())
        <section class="student-finance-records tala-task-surface" aria-labelledby="finance-fees-heading"><h2 id="finance-fees-heading">What you're paying for</h2><p class="student-finance-secondary">Your assessed fees for this term.</p><dl class="learner-money">@foreach ($assessmentLines as $line)<div><dt>{{ $line->description_snapshot }}</dt><dd>{{ $money($line->amount) }}</dd></div>@endforeach<div><dt>Total fees</dt><dd>{{ $money(data_get($accountData, 'assessment.total')) }}</dd></div></dl></section>
    @endif
    @if (data_get($accountData, 'state.coverages', []) !== [])
        <section class="student-finance-records tala-task-surface" aria-labelledby="finance-coverage-heading"><h2 id="finance-coverage-heading">Fee assistance</h2>
            @foreach (data_get($accountData, 'state.coverages', []) as $coverage)<article class="student-receipt-row"><div><h3>{{ $coverage['source'] }}</h3><p>{{ str($coverage['state'])->headline() }}@if ($coverage['effective_date'] ?? null) · {{ $coverage['effective_date'] }}@endif</p></div><strong>{{ $coverage['amount'] }}</strong></article>@endforeach
        </section>
    @endif
    <p class="student-finance-secondary">Updated {{ $date($position['as_of'] ?? null) }} · Asia/Manila</p>
</div>
