@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
<div class="accounting-proof-comparison">
    <header>
        <h2 id="proof-review-heading">Compare submitted receipt</h2>
        <p>Compare the receipt with this account’s unpaid amounts.</p>
    </header>
    @if ($pendingProofs->count() > 1)
        <nav class="accounting-proof-choices" aria-label="Submitted receipts">
            @foreach ($pendingProofs as $pending)
                <x-filament::link class="accounting-proof-choice" :href="$this->enrollmentUrl(['view' => 'proof', 'proof' => $pending->id])" :aria-current="$pending->id === $proof->id ? 'page' : null">{{ $pending->original_name }} · {{ \App\Actions\Finance\AccountingDisplay::money($pending->claimed_amount) }} · {{ \App\Actions\Finance\AccountingDisplay::dateTime($pending->submitted_at) }}</x-filament::link>
            @endforeach
        </nav>
    @endif
    <div @class(['accounting-source-comparison', 'tala-task-surface' => ! $this->isAccountingWorkspace()]) aria-labelledby="proof-review-heading">
        <section aria-labelledby="proof-submitted-heading">
            <h3 id="proof-submitted-heading">On the submitted receipt</h3>
            <p class="accounting-comparison-amount">{{ \App\Actions\Finance\AccountingDisplay::money($proof->claimed_amount) }} <span>claimed</span></p>
            <dl class="accounting-comparison-facts">
                <div><dt>Payment channel</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::channelDetailLabel($proof->channel, $proof->channel_snapshot) }}</dd></div>
                @if ($proof->payment_reference)<div><dt>Payment reference</dt><dd>{{ $proof->payment_reference }}</dd></div>@endif
                <div><dt>Paid on receipt</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::dateTime($proof->paid_at) }}</dd></div>
                <div><dt>Submitted on</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::dateTime($proof->submitted_at) }}</dd></div>
            </dl>
            <div class="tala-record-actions"><x-payment-proof-viewer :proof="$proof" label="View receipt" /></div>
        </section>
        <section aria-labelledby="proof-targets-heading">
            <h3 id="proof-targets-heading">Current unpaid target{{ $targets->count() > 1 ? 's' : '' }}</h3>
            @if ($sourceCurrent)
                <p class="accounting-comparison-amount">{{ \App\Actions\Finance\AccountingDisplay::money($targetBalance) }} <span>unpaid</span></p>
                @foreach ($targets as $target)
                    <dl class="accounting-comparison-facts" wire:key="proof-target-{{ $target['id'] }}">
                        <div><dt>Target</dt><dd>{{ $target['label'] }}</dd></div>
                        @if ($targets->count() > 1)<div><dt>Unpaid amount</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($target['available_balance']) }}</dd></div>@endif
                        <div><dt>Due date</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::dateTime($target['due_at'] ?? null) }}</dd></div>
                    </dl>
                @endforeach
            @else
                <div id="accounting-proof-blocker" class="tala-warning-banner accounting-source-warning" role="status">
                    <h4>Payment target changed</h4>
                    <p>Assessed fees or payment due dates changed since this receipt was uploaded. Review the account before recording a payment.</p>
                    <p>Submitted for {{ collect($proof->obligation_snapshot)->pluck('label')->join(', ') }}.</p>
                </div>
            @endif
        </section>
        <footer class="registrar-case-task-actions accounting-comparison-actions" style="grid-column:1/-1;padding-block-start:var(--tala-card-gap);border-block-start:1px solid var(--tala-edge);">
            @if ($sourceCurrent)
                {{ $this->getAction('verifyPaymentEvidence', isMounting: false) }}
            @else
                <x-filament::button color="gray" icon="heroicon-o-check-circle" aria-describedby="accounting-proof-blocker" disabled>Verify payment</x-filament::button>
            @endif
            {{ $this->getAction('rejectPaymentEvidence', isMounting: false)->color('gray') }}
        </footer>
    </div>
    @if ($proof->possible_duplicate)
        <div class="tala-warning-banner accounting-source-warning" role="status"><h3>Possible duplicate receipt</h3><p>Check the recorded payments before recording this receipt.</p><x-filament::link :href="$this->enrollmentUrl(['view' => 'history'])">View account history</x-filament::link></div>
    @endif
    <p class="accounting-comparison-note">The receipt has not been recorded as a payment.</p>
</div>