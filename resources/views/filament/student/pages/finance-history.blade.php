<div class="student-finance student-finance-past student-finance-history">
    @if (! $pastAccount)
        @if ($pastAccounts->isEmpty())
            <x-servitech-empty-state illustrated class="tala-task-surface student-empty-card" heading="No past statements yet" description="No earlier term accounts are recorded. Statements, payments and receipts appear here after you complete a term.">
                <x-filament::button tag="a" color="gray" :href="static::getUrl(panel: 'student')">View this term</x-filament::button>
            </x-servitech-empty-state>
        @else
            <section class="tala-task-surface student-card--two-tone" aria-labelledby="past-statements-heading">
                <div class="student-card__body">
                    <div>
                        <h2 id="past-statements-heading">Past statements and payments</h2>
                        <p class="student-finance-secondary">Choose a term to find its statements, payments and submitted receipts.</p>
                    </div>
                </div>
                <div class="student-card__table-flush student-academic-table-wrap" role="region" aria-label="Past term accounts" tabindex="0">
                    <table class="tala-data-table student-academic-table">
                        <thead>
                            <tr>
                                <th scope="col">Term</th>
                                <th scope="col">Records</th>
                                <th scope="col" class="tala-data-table-number">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($pastAccounts as $record)
                                @php
                                    $verifiedCount = $record->payments->where('state', \App\Models\Payment::StatePosted)->count();
                                    $recordStatement = $record->assessments->sortByDesc('version')->firstWhere('state', \App\Models\Assessment::StateActive)
                                        ?? $record->assessments->sortByDesc('version')->first();
                                    $recordHasStatement = $recordStatement !== null
                                        && ((float) $recordStatement->total > 0 || $recordStatement->lines->isNotEmpty());
                                @endphp
                                <tr>
                                    <th scope="row">
                                        <span>{{ $record->term?->label ?? 'Term not recorded' }}</span>
                                        @if (filled($record->term?->academicYear?->label))
                                            <span class="student-academic-secondary">{{ $record->term->academicYear->label }}</span>
                                        @endif
                                    </th>
                                    <td>
                                        <span>{{ $verifiedCount > 0 ? $verifiedCount.' verified '.str('payment')->plural($verifiedCount) : 'No verified payments' }}</span>
                                        <span class="student-academic-secondary">{{ $recordHasStatement ? 'Statement available' : 'No statement' }}</span>
                                    </td>
                                    <td class="tala-data-table-number">
                                        <x-filament::button tag="a" size="sm" color="gray" outlined :href="static::getUrl(['view' => 'history', 'account' => $record->id])">View records<span class="sr-only">: {{ $record->term?->label }}</span></x-filament::button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    @else
        @include('filament.student.pages.finance-student-changes')
        @php
            $statementAssessment = $pastAccount->assessments->firstWhere('state', \App\Models\Assessment::StateActive) ?? $pastAccount->assessments->first();
            $hasPrintableStatement = $statementAssessment !== null
                && ((float) $statementAssessment->total > 0 || $statementAssessment->lines->isNotEmpty());
            $earlierAssessments = $pastAccount->assessments->reject(fn ($assessment) => $assessment->is($statementAssessment));
        @endphp
        <section class="tala-task-surface student-card--two-tone" aria-labelledby="past-term-heading">
            <div class="student-card__body">
                <header class="student-finance-history-heading border-b border-[var(--tala-edge)] pb-3">
                    <div>
                        <h2 id="past-term-heading">{{ collect([$pastAccount->term?->label ?? 'Term not recorded', $pastAccount->term?->academicYear?->label])->filter()->implode(' · ') }}</h2>
                        <p class="student-finance-secondary">Past payments and documents for this term.</p>
                    </div>
                </header>

                @if (! $hasPrintableStatement)
                    <p class="student-finance-secondary">No statement is available for this term. Contact Accounting if you need this document.</p>
                @endif

                <div class="student-finance-history-layout">
                    <div class="student-finance-history-activity">
                        <section aria-labelledby="past-payments">
                            <h3 id="past-payments">Payments</h3>
                            @forelse ($pastAccount->payments as $payment)
                                <article class="student-finance-past-record">
                                    <div class="student-finance-history-record-heading">
                                        <h4>{{ $payment->state === \App\Models\Payment::StatePosted ? 'Verified payment' : 'Payment reversal' }}</h4>
                                        <strong>{{ $money($payment->amount) }}</strong>
                                    </div>
                                    <p>{{ $payment->channelDetailLabel() }} · {{ $date($payment->state === \App\Models\Payment::StateReversal ? ($payment->external_refund_at ?? $payment->paid_at) : $payment->paid_at) }}</p>
                                    @if ($payment->state === \App\Models\Payment::StatePosted)
                                        <x-filament::link :href="route('finance.payments.acknowledgement', $payment)" target="_blank" rel="noopener">View payment record</x-filament::link>
                                    @endif
                                </article>
                            @empty
                                <x-servitech-empty-state
                                    illustrated
                                    heading="No verified payments recorded"
                                    description="No verified payment is recorded for this term."
                                    heading-tag="h3"
                                />
                            @endforelse
                        </section>

                        @if ($pastAccount->paymentEvidenceVersions->isNotEmpty())
                            <section aria-labelledby="past-proof">
                                <h3 id="past-proof">Submitted receipts</h3>
                                <p class="student-finance-secondary">Receipt submissions are separate from verified payments.</p>
                                @foreach ($pastAccount->paymentEvidenceVersions as $proof)
                                    <article class="student-finance-past-record student-finance-history-receipt">
                                        <div>
                                            <h4>{{ $proof->original_name }}</h4>
                                            <p>Amount on receipt: {{ $money($proof->claimed_amount) }} · {{ str($proof->state)->headline() }}</p>
                                            <p>Submitted {{ $date($proof->submitted_at) }}</p>
                                            @if ($proof->review_note)
                                                <p>{{ $proof->review_note }}</p>
                                                <p class="student-finance-secondary">Reviewed {{ $date($proof->reviewed_at) }}</p>
                                            @endif
                                        </div>
                                        <x-payment-proof-viewer :proof="$proof" label="View receipt" />
                                    </article>
                                @endforeach
                            </section>
                        @endif

                        @if ($earlierAssessments->isNotEmpty())
                            <details class="learner-disclosure">
                                <summary>Earlier statements</summary>
                                <div>
                                    @foreach ($earlierAssessments as $assessment)
                                        <p><x-filament::link :href="route('finance.statement', $assessment)" target="_blank" rel="noopener">Statement{{ $assessment->created_at ? ' · '.$date($assessment->created_at) : '' }} · {{ $money($assessment->total) }}</x-filament::link></p>
                                    @endforeach
                                </div>
                            </details>
                        @endif
                    </div>

                    @if ($pastAccount->coverages->isNotEmpty())
                        <aside class="student-finance-history-assistance border border-[var(--tala-edge)]" aria-labelledby="past-sources">
                            <h3 id="past-sources">Fee assistance</h3>
                            @foreach ($pastAccount->coverages as $coverage)
                                <article class="student-finance-past-record">
                                    <h4>{{ $coverage->safe_source_description }} · {{ $money($coverage->amount) }}</h4>
                                    <p>{{ str($coverage->state)->headline() }}@if ($coverage->effective_date) · {{ $coverage->effective_date->format('M j, Y') }}@endif</p>
                                    @if ($coverage->reversal_reason)
                                        <p>{{ $coverage->reversal_reason }}</p>
                                    @endif
                                </article>
                            @endforeach
                        </aside>
                    @endif
                </div>
            </div>

            <div class="student-card__footer">
                <x-filament::button tag="a" wire:navigate color="gray" outlined icon="heroicon-o-arrow-left" :href="static::getUrl(['view' => 'history'], panel: 'student')">All past statements</x-filament::button>
                @if ($hasPrintableStatement)
                    <x-filament::button tag="a" color="gray" icon="heroicon-o-printer" :href="route('finance.statement', $statementAssessment)" target="_blank" rel="noopener">View statement</x-filament::button>
                @endif
            </div>
        </section>
    @endif
</div>
