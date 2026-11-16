@php($activityHeadingTag = $this->isAccountingWorkspace() ? 'h3' : 'h2')
<section @class(['accounting-recent-records', 'tala-task-surface' => ! $this->isAccountingWorkspace()]) aria-labelledby="account-recent-records">
    <div><{{ $activityHeadingTag }} id="account-recent-records">Payments and coverage</{{ $activityHeadingTag }}>
        @if ($latestPayment)
            <p>{{ \App\Actions\Finance\AccountingDisplay::money($latestPayment->amount) }} verified payment · {{ \App\Actions\Finance\AccountingDisplay::date($latestPayment->paid_at) }}@if ($latestPayment->reversals->isNotEmpty()) · Reversal recorded @endif</p>
            <x-filament::link :href="route('finance.payments.acknowledgement', $latestPayment)" target="_blank" rel="noopener">Open acknowledgment</x-filament::link>
        @endif
        @if ($latestCoverage)<p>{{ \App\Actions\Finance\AccountingDisplay::money($latestCoverage->amount) }} {{ strtolower(\App\Actions\Finance\AccountingDisplay::coverageCategoryLabel($latestCoverage->category)) }} · {{ \App\Actions\Finance\AccountingDisplay::coverageStateLabel($latestCoverage->state) }}</p>@endif
        @if (! $latestPayment && ! $latestCoverage)<p>No verified payments or approved coverage recorded.</p>@endif
    </div>
</section>
