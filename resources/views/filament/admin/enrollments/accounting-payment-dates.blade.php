@php
    $datesHeadingTag = $this->isAccountingWorkspace() ? 'h3' : 'h2';
    $obligationHeadingTag = $this->isAccountingWorkspace() ? 'h4' : 'h3';
@endphp
<section @class(['accounting-payment-dates', 'tala-task-surface' => ! $this->isAccountingWorkspace()]) aria-labelledby="account-payment-dates">
    <{{ $datesHeadingTag }} id="account-payment-dates">Payment dates</{{ $datesHeadingTag }}>
    <div class="accounting-obligations">
        @foreach ($position['obligations'] as $obligation)
            @php($obligationStatus = \App\Actions\Finance\AccountingDisplay::obligationStatus($obligation))
            <div wire:key="account-payment-date-{{ $obligation['id'] }}">
                <div>
                    <{{ $obligationHeadingTag }}>{{ $obligation['label'] }}</{{ $obligationHeadingTag }}>
                    <p>Due {{ \App\Actions\Finance\AccountingDisplay::dateTime($obligation['due_at'] ?? null) }}</p>
                    @if ((float) $obligation['payment_applied'] > 0)<p>{{ \App\Actions\Finance\AccountingDisplay::money($obligation['payment_applied']) }} verified payment</p>@endif
                    @if ((float) $obligation['coverage_applied'] > 0)<p>{{ \App\Actions\Finance\AccountingDisplay::money($obligation['coverage_applied']) }} approved coverage</p>@endif
                </div>
                <div class="accounting-obligation-balance">
                    <x-filament::badge :color="$obligationStatus['color']">{{ $obligationStatus['label'] }}</x-filament::badge>
                    <p>{{ \App\Actions\Finance\AccountingDisplay::money($obligation['balance']) }} remaining</p>
                </div>
            </div>
        @endforeach
    </div>
    <div class="registrar-case-task-actions accounting-context-actions">{{ $this->getAction('recordApprovedCoverage', isMounting: false)->color('gray') }}</div>
</section>
