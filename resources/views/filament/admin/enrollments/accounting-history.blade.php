@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php
    $money = fn ($value) => 'PHP '.number_format((float) $value, 2);
    $date = fn ($value) => $value ? \App\Support\DisplayDateTime::format($value, 'M j, Y · g:i A') : 'Date not recorded';
@endphp
<div class="registrar-case">
    <div class="registrar-case-identity"><p>{{ $case->credentialUser?->getFilamentName() }} · {{ $case->studentProfile?->student_number ?? $case->admissionApplication?->application_reference }}</p><p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }} · {{ $case->term?->label }}</p></div>
    <div class="registrar-history-grid accounting-account-history">
        <div>
            <section aria-labelledby="account-history-payments"><h2 id="account-history-payments">Payments</h2>
                @forelse ($account->payments->sortByDesc('paid_at') as $payment)
                    <article class="accounting-history-record" wire:key="account-history-payment-{{ $payment->id }}">
                        <h3>{{ $money($payment->amount) }} · {{ $payment->state === \App\Models\Payment::StateReversal ? 'Reversal' : ($payment->evidence_status === 'verified' && $payment->verified_at ? 'Verified payment' : 'Unverified claim · '.str($payment->evidence_status)->headline()) }}</h3>
                        <p>{{ $date($payment->paid_at) }} · {{ $payment->channelLabel() }}</p>
                        @if ($payment->state === \App\Models\Payment::StatePosted && $payment->evidence_status === 'verified' && $payment->verified_at)<x-filament::link :href="route('finance.payments.acknowledgement', $payment)" target="_blank" rel="noopener">Open acknowledgment</x-filament::link>@endif
                        <details class="registrar-case-reference"><summary>Payment approval details</summary>
                            <dl class="registrar-source-charges">
                                @foreach (['Payment reference' => $payment->provider_reference, 'Verified by' => $payment->verifier?->getFilamentName(), 'Verified at' => $payment->verified_at ? $date($payment->verified_at) : null, 'Verification basis' => $payment->verification_basis, 'External check' => $payment->external_check_reference, 'Official receipt' => $payment->or_number, 'Reversal approval' => $payment->reversal_authority_reference, 'Reason' => $payment->reversal_reason, 'External refund' => $payment->external_refund_reference] as $label => $value)
                                    @if (filled($value))<div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>@endif
                                @endforeach
                            </dl>
                            @foreach ($payment->allocations as $allocation)<p>{{ $allocation->assessmentObligation?->label }} · {{ $money($allocation->amount) }}</p>@endforeach
                        </details>
                    </article>
                @empty<p class="registrar-case-meta">No verified payments recorded.</p>@endforelse
                <div class="accounting-context-actions">{{ $this->getAction('reversePaymentPosting', isMounting: false)->color('gray') }}</div>
            </section>
            @if ($account->paymentEvidenceVersions->isNotEmpty())
                <section aria-labelledby="account-history-proof"><h2 id="account-history-proof">Payment proof</h2>
                    @foreach ($account->paymentEvidenceVersions->sortByDesc('version') as $proof)
                        <article class="accounting-history-record" wire:key="account-history-proof-{{ $proof->id }}"><h3>{{ $proof->original_name }} · {{ str($proof->state)->headline() }}</h3><p>{{ $money($proof->claimed_amount) }} claimed · {{ $date($proof->submitted_at) }}</p><p>{{ $proof->payment_reference }}</p>
                            <x-filament::link :href="route('finance.payment-evidence.download', $proof)">Open private proof</x-filament::link>
                            <details class="registrar-case-reference"><summary>Proof review details</summary><p>Version {{ $proof->version }} · {{ collect($proof->obligation_snapshot ?? [])->pluck('label')->filter()->implode(', ') }}</p>@if ($proof->reviewed_at)<p>{{ $date($proof->reviewed_at) }} · {{ $proof->review_note }}</p>@endif @if ($proof->possible_duplicate)<p>Possible duplicate: compare the original external source before verification.</p>@endif</details>
                        </article>
                    @endforeach
                </section>
            @endif
            @if ($account->coverages->isNotEmpty())
                <section aria-labelledby="account-history-coverage"><h2 id="account-history-coverage">Approved coverage</h2>
                    @foreach ($account->coverages->sortByDesc('approved_at') as $coverage)
                        <article class="accounting-history-record" wire:key="account-history-coverage-{{ $coverage->id }}"><h3>{{ $money($coverage->amount) }} · {{ str($coverage->category)->headline() }}</h3><p>{{ $coverage->safe_source_description }} · {{ $coverage->state }}</p><p>{{ $coverage->obligation?->label }} · Effective {{ $coverage->effective_date?->format('M j, Y') }}</p><details class="registrar-case-reference"><summary>Coverage approval details</summary><p>{{ $coverage->authority_reference }} · {{ $coverage->authority_date?->format('M j, Y') }}</p>@if ($coverage->reversed_at)<p>{{ $coverage->reversal_authority_reference }} · {{ $coverage->reversal_reason }}</p>@endif</details></article>
                    @endforeach
                    <div class="accounting-context-actions">{{ $this->getAction('reverseApprovedCoverage', isMounting: false)->color('gray') }}</div>
                </section>
            @endif
            @if ($account->paymentAttempts->isNotEmpty())
                <section aria-labelledby="account-history-online"><h2 id="account-history-online">Online payments</h2>@foreach ($account->paymentAttempts->sortByDesc('created_at') as $attempt)<article class="accounting-history-record"><h3>{{ $money($attempt->amount) }} · {{ str($attempt->status)->headline() }}</h3><p>{{ $date($attempt->created_at) }} · {{ $attempt->internal_reference }}</p></article>@endforeach</section>
            @endif
        </div>
        <aside class="registrar-case-sources">
            @if (isset($onlineHistory) && $onlineHistory->isNotEmpty())
                <section aria-labelledby="account-history-online-reviews"><h2 id="account-history-online-reviews">Online payment reviews</h2>
                    @foreach ($onlineHistory as $review)
                        <article class="accounting-history-record"><h3>{{ ($review->status === \App\Models\OperationalEvent::StatusPending ? 'Queued for processing' : str(data_get($review->diagnostics, 'resolution.action', 'Awaiting review'))->headline()) }}</h3><p>{{ data_get($review->payload, 'reference_number') }}</p><p>{{ $date($review->processed_at ?? $review->occurred_at) }}</p>
                            @if (filled($reviewReason = data_get($review->diagnostics, 'resolution.reason', data_get($review->diagnostics, 'reconciliation.reason'))))<p>{{ $reviewReason }}</p>@endif
                        </article>
                    @endforeach
                </section>
            @endif
            <section aria-labelledby="account-history-assessments"><h2 id="account-history-assessments">Assessments</h2>
                @foreach ($account->assessments->sortByDesc('version') as $recordedAssessment)
                    <article class="accounting-history-record" wire:key="account-history-assessment-{{ $recordedAssessment->id }}"><h3>Assessment version {{ $recordedAssessment->version }} · {{ $recordedAssessment->state === \App\Models\Assessment::StateActive ? 'Current' : str($recordedAssessment->state)->headline() }}</h3><p>{{ $money($recordedAssessment->total) }} · {{ str($recordedAssessment->assessment_basis)->headline() }}</p><p>{{ $recordedAssessment->authority_reference }} · {{ $recordedAssessment->authority_date?->format('M j, Y') }}</p>
                        <x-filament::link :href="route('finance.statement', $recordedAssessment)" target="_blank" rel="noopener">Open statement</x-filament::link>
                        <details class="registrar-case-reference"><summary>Charge details</summary><dl class="registrar-source-charges">@foreach ($recordedAssessment->lines as $line)<div><dt>{{ $line->description_snapshot }}</dt><dd>{{ $money($line->amount) }}</dd></div>@endforeach</dl>@if (data_get($recordedAssessment->source_snapshot, 'no_payment_required_authority_reference'))<p>No enrollment payment: {{ data_get($recordedAssessment->source_snapshot, 'no_payment_required_authority_reference') }}</p>@endif</details>
                    </article>
                @endforeach
            </section>
            <section aria-labelledby="account-history-exports"><h2 id="account-history-exports">Exports</h2><div class="accounting-context-actions">{{ $this->getAction('exportTermAccount', isMounting: false)->color('gray') }}</div>
                @foreach ($account->financeExports->sortByDesc('created_at') as $export)
                    <div class="accounting-history-record">
                        <p>{{ $date($export->generated_at) }} · {{ $export->row_count }} {{ str('row')->plural($export->row_count) }} · {{ str($export->outcome)->headline() }}</p>
                        @if ($export->outcome === \App\Models\FinanceExport::OutcomeGenerated && (int) $export->initiated_by === (int) auth()->id())
                            <x-filament::link :href="route('finance.exports.download', $export)">Download recorded export</x-filament::link>
                        @elseif ($export->outcome === \App\Models\FinanceExport::OutcomeGenerated)
                            <p>Private file available to the Accounting user who generated it.</p>
                        @endif
                    </div>
                @endforeach
            </section>
            @if ($proposal)<section><h2>Registration subjects</h2><details class="registrar-case-reference"><summary>View the selection used for registration</summary>@include('filament.admin.enrollments.subjects', ['sourceKey' => 'account-history'])</details></section>@endif
        </aside>
    </div>
</div>
