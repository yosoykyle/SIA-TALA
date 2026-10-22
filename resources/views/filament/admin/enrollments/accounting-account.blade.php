@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php
    $money = fn ($value) => 'PHP '.number_format((float) $value, 2);
    $ordinary = $reviewState === 'ordinary';
    $due = $ordinary && (float) $position['current_due'] > 0;
    $later = collect($position['obligations'])->where('is_due', false)->sum('balance');
    $latestPayment = $account->payments->where('state', \App\Models\Payment::StatePosted)->where('evidence_status', 'verified')->whereNotNull('verified_at')->sortByDesc('paid_at')->first();
    $latestCoverage = $account->coverages->sortByDesc('approved_at')->first();
    $historyUrl = \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history']);
@endphp
<div class="registrar-case accounting-current-account">
    <div class="registrar-case-identity">
        <p>{{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }}</p>
        <p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    @if (! $ordinary)@include('filament.admin.enrollments.accounting-account-review')@endif
    @if ($account->paymentEvidenceVersions->contains('state', \App\Models\PaymentEvidenceVersion::StateSubmitted))
        <div class="accounting-context-actions"><x-filament::button tag="a" :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'proof'])">Review submitted payment proof</x-filament::button></div>
    @endif
    @if ($account->paymentAttempts->whereIn('status', \App\Models\PaymentAttempt::ActiveStatuses)->isNotEmpty())
        <div class="accounting-context-actions"><x-filament::button tag="a" :href="\App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'online'])">Review online payment</x-filament::button></div>
    @endif
    @if ($ordinary)<section class="accounting-account-summary" aria-labelledby="account-current-status">
        <h2 id="account-current-status">{{ $due ? $money($position['current_due']).' due now' : 'No payment due now' }}</h2>
        <p>{{ $requirement['state'] === 'Cleared' ? 'Enrollment payment satisfied' : 'Enrollment payment pending' }}@if ($later > 0) · Later fees {{ $money($later) }}@endif</p>
    </section>@endif
    <div class="registrar-case-grid">
        @if ($ordinary)<section aria-labelledby="account-payment-dates">
            <h2 id="account-payment-dates">Payment dates</h2>
            <div class="accounting-obligations">
                @foreach ($position['obligations'] as $obligation)
                    <div wire:key="account-payment-date-{{ $obligation['id'] }}">
                        <div>
                            <h3>{{ $obligation['label'] }}</h3>
                            <p>Due {{ \App\Support\DisplayDateTime::format($obligation['due_at'] ? \Carbon\CarbonImmutable::parse($obligation['due_at']) : null, 'M j, Y · g:i A') }}</p>
                            @if ((float) $obligation['payment_applied'] > 0)<p>{{ $money($obligation['payment_applied']) }} verified payment</p>@endif
                            @if ((float) $obligation['coverage_applied'] > 0)<p>{{ $money($obligation['coverage_applied']) }} approved coverage</p>@endif
                        </div>
                        <div class="accounting-obligation-balance">
                            <x-filament::badge :color="(float) $obligation['balance'] === 0.0 ? 'success' : ($obligation['is_due'] ? 'warning' : 'gray')">{{ (float) $obligation['balance'] === 0.0 ? 'Satisfied' : ($obligation['is_due'] ? 'Due now' : 'Later') }}</x-filament::badge>
                            <p>{{ $money($obligation['balance']) }} remaining</p>
                        </div>
                    </div>
                @endforeach
            </div>
            <dl class="registrar-source-charges"><div><dt>Remaining term fees</dt><dd>{{ $money($position['remaining_balance']) }}</dd></div></dl>
            <div class="registrar-case-task-actions accounting-context-actions">{{ $this->getAction('recordApprovedCoverage', isMounting: false)->color('gray') }}</div>
        </section>@endif
        <section aria-labelledby="account-approved-charges">
            <h2 id="account-approved-charges">{{ $ordinary ? 'Approved charges' : 'Recorded charges' }}</h2>
            @if ($recordedAssessment)
            <dl class="registrar-source-charges">
                @foreach ($recordedAssessment->lines as $line)<div><dt>{{ $line->description_snapshot }}</dt><dd>{{ $money($line->amount) }}</dd></div>@endforeach
                <div><dt><strong>Total</strong></dt><dd><strong>{{ $money($recordedAssessment->total) }}</strong></dd></div>
            </dl>
            <details class="registrar-case-reference"><summary>Approval details</summary>
                <p>Assessment version {{ $recordedAssessment->version }} · {{ str($recordedAssessment->assessment_basis)->headline() }} · {{ $recordedAssessment->state }}</p>
                <p>{{ $recordedAssessment->authority_reference }} · {{ $recordedAssessment->authority_date?->format('M j, Y') }}</p>
                @if (data_get($recordedAssessment->source_snapshot, 'no_payment_required_authority_reference'))<p>No enrollment payment: {{ data_get($recordedAssessment->source_snapshot, 'no_payment_required_authority_reference') }}</p>@endif
            </details>
            <div class="accounting-context-actions"><x-filament::button tag="a" :href="route('finance.statement', $recordedAssessment)" target="_blank" rel="noopener" color="gray" icon="heroicon-o-document-text">Open account statement</x-filament::button></div>
            @else<p>No assessment has been recorded for this account.</p>@endif
        </section>
        @if (! $ordinary)@include('filament.admin.enrollments.accounting-account-activity')@endif
    </div>
    @if ($ordinary)@include('filament.admin.enrollments.accounting-account-activity')@endif
</div>
