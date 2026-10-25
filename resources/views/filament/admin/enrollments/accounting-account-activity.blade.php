<section class="accounting-recent-records tala-task-surface" aria-labelledby="account-recent-records">
    <div><h2 id="account-recent-records">Payments and coverage</h2>
        @if ($latestPayment)
            <p>{{ $money($latestPayment->amount) }} verified payment · {{ \App\Support\DisplayDateTime::format($latestPayment->paid_at, 'M j, Y') }}@if ($latestPayment->reversals->isNotEmpty()) · Reversal recorded @endif</p>
            <x-filament::link :href="route('finance.payments.acknowledgement', $latestPayment)" target="_blank" rel="noopener">Open acknowledgment</x-filament::link>
        @endif
        @if ($latestCoverage)<p>{{ $money($latestCoverage->amount) }} {{ str($latestCoverage->category)->headline()->lower() }} · {{ str($latestCoverage->state)->headline() }}</p>@endif
        @if (! $latestPayment && ! $latestCoverage)<p>No verified payments or approved coverage recorded.</p>@endif
    </div>
    @if ($reviewState !== 'ended')<x-filament::link :href="$historyUrl" icon="heroicon-o-arrow-right" icon-position="after">Account history</x-filament::link>@endif
</section>
