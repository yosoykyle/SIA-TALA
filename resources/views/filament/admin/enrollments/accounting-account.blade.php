@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $ordinary = $reviewState === 'ordinary';
    $due = $ordinary && (float) $position['current_due'] > 0;
    $later = collect($position['obligations'])->where('is_due', false)->sum('balance');
    $latestPayment = $account->payments->filter(\App\Actions\Finance\AccountingDisplay::isVerifiedPayment(...))->sortByDesc('paid_at')->first();
    $latestCoverage = $account->coverages->sortByDesc('approved_at')->first();
    $historyUrl = $this->enrollmentUrl(['view' => 'history']);
@endphp
<div class="registrar-case accounting-current-account">
    @if (! $ordinary)@include('filament.admin.enrollments.accounting-account-review')@endif
    <div class="accounting-account-highlights">
        @if ($ordinary)
            <section @class(['accounting-current-payment', 'tala-task-surface' => ! $this->isAccountingWorkspace()]) aria-labelledby="account-current-status">
                <p class="accounting-payment-kicker"><span class="tala-workspace-card__icon"><x-filament::icon icon="heroicon-o-wallet" aria-hidden="true" /></span>{{ $due ? 'Due now' : 'Current payment' }}</p>
                <h2 id="account-current-status">{{ $due ? \App\Actions\Finance\AccountingDisplay::money($position['current_due']) : 'No payment due now' }}</h2>
                <p>{{ $requirement['state'] === 'Cleared' ? 'Enrollment payment satisfied' : 'Enrollment payment pending' }}</p>
                <dl><div><dt>Remaining term fees</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($position['remaining_balance']) }}</dd></div>@if ($later > 0)<div><dt>Later fees</dt><dd>{{ \App\Actions\Finance\AccountingDisplay::money($later) }}</dd></div>@endif</dl>
                @if ($recordedAssessment || $this->getAction('recordOnsitePayment', isMounting: false)?->isVisible())
                    <div class="registrar-case-task-actions">
                        @if ($this->getAction('recordOnsitePayment', isMounting: false)?->isVisible())
                            {{ $this->getAction('recordOnsitePayment', isMounting: false) }}
                        @endif
                        @if ($recordedAssessment)
                            <x-filament::button tag="a" :href="route('finance.statement', $recordedAssessment)" target="_blank" rel="noopener" color="gray" icon="heroicon-m-arrow-right" icon-position="after">Open account statement</x-filament::button>
                        @endif
                    </div>
                @endif
            </section>
            @include('filament.admin.enrollments.accounting-charges')
        @endif

    </div>
    @if (($lifecycleStatus['pending'] ?? 0) > 0)
        @include('filament.admin.enrollments.accounting-lifecycle')
    @endif
    <div class="registrar-case-grid accounting-account-details">
        @if ($ordinary)@include('filament.admin.enrollments.accounting-payment-dates')@endif
        @unless ($ordinary)@include('filament.admin.enrollments.accounting-charges')@endunless
        @if (! $ordinary)@include('filament.admin.enrollments.accounting-account-activity')@endif
    </div>
    @if ($ordinary)@include('filament.admin.enrollments.accounting-account-activity')@endif
    @if ($case->studentProfile?->student_number)
        <div class="accounting-context-actions">
            <x-filament::link icon="heroicon-o-shield-exclamation" :href="\App\Filament\Resources\TranscriptRequests\TranscriptRequestResource::getUrl('index', ['search' => $case->studentProfile->student_number])">Transcript of Records (TOR) requests and restrictions</x-filament::link>
        </div>
    @endif
</div>
