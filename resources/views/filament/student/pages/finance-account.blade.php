@php
    $available = ($accountData['available'] ?? false) && ($position['state'] ?? 'Unavailable') !== 'Unavailable';
    $due = $available && (float) $position['current_due'] > 0;
    $latestPending = $latestEvidence?->state === \App\Models\PaymentEvidenceVersion::StateSubmitted;
    $latestRejected = $latestEvidence?->state === \App\Models\PaymentEvidenceVersion::StateRejected && $due;
    $later = $available ? collect($position['obligations'])->where('is_due', false)->sum('balance') : null;
    $resolvedHowToPayDisplay = is_array($howToPayDisplay ?? null)
        ? $howToPayDisplay
        : data_get($accountData, 'how_to_pay_display', data_get($accountData, 'where_to_pay_display'));
    $hasElectronicChannels = is_bool($hasEnabledDigitalPaymentChannels ?? null)
        ? $hasEnabledDigitalPaymentChannels
        : (bool) (data_get($accountData, 'has_enabled_digital_channels')
            ?? data_get($resolvedHowToPayDisplay, 'hasEnabledDigitalChannels', collect(data_get($resolvedHowToPayDisplay, 'electronicChannels', []))->isNotEmpty()));
@endphp
@php
    $financialHolds = $activeFinancialHolds ?? collect();
    $hasFinancialHolds = $financialHolds->isNotEmpty();
@endphp
<div class="student-finance student-finance-workspace">
    @if ($hasFinancialHolds)
        <section class="tala-notice--warning student-finance-hold-banner" role="status" aria-labelledby="finance-prior-hold-heading">
            <div class="student-finance-hold-banner__head">
                <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                <h2 id="finance-prior-hold-heading">Prior-balance financial hold on your account</h2>
            </div>
            @foreach ($financialHolds as $hold)
                <p>{{ $hold->studentFacingMessage() }}</p>
                @if (filled($hold->reason) && $hold->reason !== $hold->studentFacingMessage() && $hold->reason !== $hold->staff_only_reason)
                    <p class="student-finance-hold-note"><strong>Hold note:</strong> {{ $hold->reason }}</p>
                @endif
            @endforeach
            <p class="student-finance-secondary">This prior-balance hold may block next-term enrollment and official record releases (such as your TOR or graduation clearance). Please coordinate with the Accounting Office; Accounting or the Registrar will lift the hold once settled.</p>
        </section>
    @endif
    @if (! isset($accountData['account']))
        @foreach ($unavailableAccountReviews as $review)
            @include('filament.student.pages.finance-student-changes', ['studentChangeReviewStatus' => $review['status'], 'studentChangeReviewTerm' => $review['term'], 'studentChangeReviewId' => $review['id']])
        @endforeach
        <section class="student-record-empty tala-task-surface">
            @if ($hasFinancialHolds)
                <x-servitech-empty-state
                    illustrated
                    tone="neutral"
                    heading="No current-term assessment yet"
                    description="Once your prior balance is cleared and you enroll for the next term, your current-term fees and payment schedule will appear here."
                >
                    <x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Refresh account</x-filament::button>
                </x-servitech-empty-state>
            @else
                <x-servitech-empty-state illustrated heading="Your fees will appear here" description="Your fees and payment schedule will appear after Accounting prepares your term account.">
                    <x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Refresh account</x-filament::button>
                </x-servitech-empty-state>
            @endif
        </section>
    @else
    <p class="student-information-intro">{{ data_get($accountData, 'state.term') }}</p>
    @include('filament.student.pages.finance-student-changes')
    <div wire:loading.delay wire:target="refreshFinance" role="status">Refreshing your account…</div>
    @if (! $available)
        <section class="tala-task-surface student-card--two-tone student-finance-task" aria-labelledby="finance-unavailable">
            <div class="student-card__body">
                <h2 id="finance-unavailable">Account information unavailable</h2>
                <p>Your fees or payment records need an Accounting review. Contact Accounting before making a payment.</p>
            </div>
            <div class="student-card__footer">
                <x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Refresh account</x-filament::button>
            </div>
        </section>
    @else
        @php
            $submitEvidenceAction = $this->getAction('submitEvidence', isMounting: false);
            $canSubmitEvidence = $hasElectronicChannels && $submitEvidenceAction !== null && $submitEvidenceAction->isVisible();
        @endphp
        <div class="student-overview-priority-grid">
            <section class="tala-task-surface student-card--two-tone student-finance-task {{ $latestRejected ? 'student-finance-task-review' : '' }} {{ $due && ! $latestPending && ! $latestRejected ? 'student-finance-task-due' : '' }}" aria-labelledby="finance-task-heading">
                @if ($latestPending)
                    <div class="student-card__body">
                        <h2 id="finance-task-heading">Submitted receipt awaiting review</h2>
                        <p>Accounting will review your {{ $money($latestEvidence->claimed_amount) }} submitted receipt before your balance changes.</p>
                        <x-workflow-handoff current-owner="Accounting" current-task="Review submitted receipt" next-owner="You" next-task="View updated account" current-icon="heroicon-o-banknotes" next-icon="heroicon-o-document-text" />
                        <p>{{ $latestEvidence->original_name }}<span class="student-finance-secondary">Submitted {{ $date($latestEvidence->submitted_at) }}</span></p>
                    </div>
                    <div class="student-card__footer">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-payment-proof-viewer :proof="$latestEvidence" label="View receipt" primary />
                            <x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Refresh status</x-filament::button>
                        </div>
                    </div>
                @elseif ($latestRejected)
                    <div class="student-card__body">
                        <h2 id="finance-task-heading">Submitted receipt needs correction</h2>
                        <p>{{ $latestEvidence->review_note ?: 'Contact Accounting for the review reason, or upload a clearer receipt below.' }}</p>
                        <p>{{ $latestEvidence->original_name }} · {{ $money($latestEvidence->claimed_amount) }} submitted<span class="student-finance-secondary">Reviewed {{ $date($latestEvidence->reviewed_at) }}</span></p>
                        @if (! $hasElectronicChannels)
                            <p class="student-finance-secondary">Electronic receipt uploads are currently turned off. Pay onsite at the Campus Cashier below; onsite payments are recorded directly without uploading a receipt.</p>
                        @endif
                    </div>
                    <div class="student-card__footer">
                        <div class="flex flex-wrap items-center gap-2">
                            @if ($canSubmitEvidence)
                                {{ $submitEvidenceAction }}
                            @endif
                            <x-payment-proof-viewer :proof="$latestEvidence" label="View receipt" />
                        </div>
                    </div>
                @elseif ($due)
                    <div class="student-card__body">
                        <h2 id="finance-task-heading">Your next payment</h2>
                        <p class="student-finance-next-amount">{{ $money($position['current_due']) }}</p>
                        @if ($position['next_obligation'])
                            <p class="student-finance-secondary">{{ $position['next_obligation']['label'] }} · Due {{ $dueDate($position['next_obligation']['due_at']) }}</p>
                        @endif
                        @if ($hasElectronicChannels)
                            <p>Pay through a school payment channel below, or visit the Campus Cashier. If you paid by bank transfer or e-wallet, submit your receipt for Accounting to review.</p>
                        @else
                            <p>Electronic transfer channels are currently unavailable. Pay onsite at the Campus Cashier below; onsite payments are recorded directly without uploading a receipt.</p>
                        @endif
                    </div>
                    <div class="student-card__footer">
                        @if ($canSubmitEvidence)
                            {{ $submitEvidenceAction->color('primary') }}
                        @else
                            <x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Refresh account</x-filament::button>
                        @endif
                    </div>
                @else
                    <div class="student-card__body">
                        <h2 class="student-record-heading" id="finance-task-heading"><x-filament::icon icon="heroicon-o-check-circle" aria-hidden="true" />No payment due today</h2>
                        @if ($position['next_obligation'])
                            <p class="student-finance-next-label">Next payment</p>
                            <p class="student-finance-next-amount">{{ $money($position['next_obligation']['balance']) }}</p>
                            <p class="student-finance-secondary">Due {{ $dueDate($position['next_obligation']['due_at']) }}</p>
                        @else
                            <p>No payments remain for this term.</p>
                        @endif
                    </div>
                    <div class="student-card__footer">
                        <x-filament::button wire:click="refreshFinance" wire:loading.attr="disabled" color="gray" icon="heroicon-o-arrow-path">Refresh account</x-filament::button>
                    </div>
                @endif
            </section>
            <section class="tala-task-surface student-card--two-tone student-finance-position" aria-labelledby="finance-position-heading">
                <div class="student-card__body">
                    <h2 class="student-record-heading" id="finance-position-heading"><x-filament::icon icon="heroicon-o-scale" aria-hidden="true" />Account balance</h2>
                    @php
                        $assessedTotal = (float) data_get($accountData, 'assessment.total', 0);
                        $settled = max(0, $assessedTotal - (float) $position['remaining_balance']);
                        $settledPercent = $assessedTotal > 0 ? (int) round(min(1, $settled / $assessedTotal) * 100) : 0;
                    @endphp
                    @if ($assessedTotal > 0)
                        <div class="student-tile-bar" role="img" aria-label="{{ $settledPercent }} percent of this term's fees settled"><span style="inline-size: {{ $settledPercent }}%"></span></div>
                        <p class="student-finance-secondary">{{ $money($settled) }} settled of {{ $money($assessedTotal) }}</p>
                    @endif
                    <dl>
                        <div><dt>Amount due now</dt><dd>{{ $money($position['current_due']) }}</dd></div>
                        <div><dt>Later fees</dt><dd>{{ $money($later) }}</dd></div>
                        <div class="border-b-0"><dt>Remaining term balance</dt><dd>{{ $money($position['remaining_balance']) }}</dd></div>
                    </dl>
                </div>
                <div class="student-card__footer">
                    {{ $this->getAction('statement', isMounting: false) }}
                </div>
            </section>
        </div>

        @php
            $cashChannel = is_array($resolvedHowToPayDisplay['cashChannel'] ?? null) ? $resolvedHowToPayDisplay['cashChannel'] : null;
        @endphp
        @if (is_array($cashChannel) && ((float) ($position['remaining_balance'] ?? 0) > 0 || ! $hasElectronicChannels))
            @php
                $cashToday = is_array($cashChannel['today_status'] ?? null) ? $cashChannel['today_status'] : null;
                $cashTone = in_array($cashToday['tone'] ?? '', ['success', 'warning', 'gray'], true) ? $cashToday['tone'] : 'gray';
                $cashTodayDetail = filled($cashToday['detail'] ?? null)
                    ? preg_replace('/\s*·\s*\d{1,2}:\d{2}\s*[AP]M\s*[–-]\s*\d{1,2}:\d{2}\s*[AP]M$/u', '', (string) $cashToday['detail'])
                    : null;
                $cashWeeklySummary = collect($cashChannel['weekly_summary_rows'] ?? [])
                    ->map(fn (array $row): string => $row['days'].' '.(strtolower(trim((string) ($row['hours'] ?? ''))) === 'closed' ? 'Closed' : $row['hours']))
                    ->implode(', ');
                $cashLocationParts = array_values(array_filter([
                    filled($cashChannel['location'] ?? null) ? $cashChannel['location'] : null,
                    filled($cashChannel['cutoff_note'] ?? null) ? $cashChannel['cutoff_note'] : null,
                ]));
                $cashNow = \Carbon\CarbonImmutable::now($cashToday['timezone'] ?? config('app.display_timezone'));
                $weekStart = $cashNow->startOfWeek(\Carbon\CarbonInterface::MONDAY)->toDateString();
                $weekEnd = $cashNow->endOfWeek(\Carbon\CarbonInterface::SUNDAY)->toDateString();
                $weekExceptions = collect($cashChannel['upcoming_exceptions'] ?? [])
                    ->filter(fn (array $ex): bool => ($ex['ends_on'] ?? '') >= $weekStart && ($ex['starts_on'] ?? '') <= $weekEnd)
                    ->values();
            @endphp
            <section class="tala-task-surface student-finance-cashier-summary" aria-labelledby="finance-cashier-heading">
                <div class="student-finance-cashier-summary__head">
                    <h2 class="student-record-heading" id="finance-cashier-heading">
                        <x-filament::icon icon="heroicon-o-building-storefront" aria-hidden="true" />
                        <span>Pay in cash · {{ $cashChannel['label'] ?? 'Campus Cashier' }}</span>
                    </h2>
                    <div class="student-finance-cashier-summary__badges">
                        @if ($cashToday !== null)
                            <x-filament::badge :color="$cashTone" size="sm">{{ $cashToday['badge'] }}</x-filament::badge>
                        @endif
                        <span class="tala-channel-card__mode-pill">Onsite · No upload</span>
                    </div>
                </div>
                @if (filled($cashTodayDetail) || $cashLocationParts !== [])
                    <p class="student-finance-cashier-summary__line">
                        @if (filled($cashTodayDetail))
                            <strong>{{ $cashTodayDetail }}</strong>@if ($cashLocationParts !== [])<span> · {{ implode(' · ', $cashLocationParts) }}</span>@endif
                        @else
                            <span>{{ implode(' · ', $cashLocationParts) }}</span>
                        @endif
                    </p>
                @endif
                @if ($cashWeeklySummary !== '' || $weekExceptions->isNotEmpty())
                    <p class="student-finance-secondary">
                        @if ($cashWeeklySummary !== '')
                            <span>This week: {{ $cashWeeklySummary }}</span>
                        @endif
                        @foreach ($weekExceptions as $ex)
                            <span>{{ $cashWeeklySummary !== '' || ! $loop->first ? ' · ' : '' }}{{ $ex['date_label'] }}: {{ $ex['label'] }} ({{ ($ex['status'] ?? '') === 'custom_hours' && ! empty($ex['hours_label']) ? $ex['hours_label'] : 'Closed' }})</span>
                        @endforeach
                    </p>
                @endif
            </section>
        @endif
    @endif

    @if ($available)
        <section class="student-finance-section tala-task-surface" aria-labelledby="payment-schedule-heading">
            <h2 class="student-record-heading" id="payment-schedule-heading">
                <x-filament::icon icon="heroicon-o-calendar-days" aria-hidden="true" />Payment schedule
            </h2>
            @if (empty($position['obligations']))
                <x-servitech-empty-state
                    illustrated
                    heading="No installments scheduled"
                    description="No installments are recorded for this term."
                    heading-tag="h3"
                />
            @else
                <ul class="student-payment-schedule">
                    @foreach ($position['obligations'] as $obligation)
                        <li wire:key="account-obligation-{{ $obligation['id'] }}"
                            @class(['student-schedule-done' => $obligation['balance'] === '0.00', 'student-schedule-due' => $obligation['balance'] !== '0.00' && $obligation['is_due']])>
                            <div>
                                <h3>{{ $obligation['label'] }}</h3>
                                <p class="student-finance-secondary">Due {{ $dueDate($obligation['due_at']) }}</p>
                                @if ((float) $obligation['payment_applied'] > 0)
                                    <p class="student-finance-secondary">{{ $money($obligation['payment_applied']) }} verified payment</p>
                                @endif
                                @if ((float) $obligation['coverage_applied'] > 0)
                                    <p class="student-finance-secondary">{{ $money($obligation['coverage_applied']) }} approved fee assistance</p>
                                @endif
                            </div>
                            <div class="student-finance-installment">
                                <strong class="student-finance-number">{{ $money($obligation['amount']) }}</strong>
                                @if ((float) $obligation['balance'] > 0 && (float) $obligation['balance'] < (float) $obligation['amount'])
                                    <span class="student-finance-secondary">{{ $money($obligation['balance']) }} remaining</span>
                                @endif
                                <x-filament::badge :color="$obligation['balance'] === '0.00' ? 'success' : ($obligation['is_due'] ? 'warning' : 'gray')">
                                    {{ $obligation['balance'] === '0.00' ? 'Satisfied' : ($obligation['is_due'] ? 'Due' : 'Upcoming') }}
                                </x-filament::badge>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    <section class="student-finance-records tala-task-surface" aria-labelledby="payments-heading">
        <div class="student-finance-activity-head">
            <h2 class="student-record-heading" id="payments-heading"><x-filament::icon icon="heroicon-o-banknotes" />Payments &amp; recent activity</h2>
        </div>
        @if ($payments->isEmpty())
            <x-servitech-empty-state
                illustrated
                heading="No verified payments recorded"
                description="No verified payment is recorded for this term. Submitted receipts do not change your balance."
                heading-tag="h3"
            />
        @else
            @php($latestPayments = $payments->sortByDesc('paid_at')->take(3))
            <ul class="student-finance-compact-activity" aria-label="Recent payment activity">
                @foreach ($latestPayments as $payment)
                    <li wire:key="finance-payment-{{ $payment->id }}">
                        <div>
                            <strong>{{ $payment->state === \App\Models\Payment::StatePosted ? 'Verified payment' : 'Payment reversal' }}</strong>
                            <span class="student-finance-secondary">{{ $date($payment->state === \App\Models\Payment::StateReversal ? ($payment->external_refund_at ?? $payment->paid_at) : $payment->paid_at) }} · {{ $payment->channelDetailLabel() }}</span>
                        </div>
                        <div class="student-finance-compact-activity__end">
                            <strong class="student-finance-number">{{ $money($payment->amount) }}</strong>
                            @if ($payment->state === \App\Models\Payment::StatePosted)
                                <x-filament::button tag="a" size="xs" color="gray" outlined :href="route('finance.payments.acknowledgement', $payment)" target="_blank" rel="noopener">Acknowledgment</x-filament::button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($payments->count() > 3)
                <p class="student-finance-secondary">Showing latest 3 of {{ $payments->count() }} entries. Open Print / Export Statement for the full chronological record.</p>
            @endif
        @endif
        @php($otherReceipts = $evidence->reject(fn ($item) => $available && $item->is($latestEvidence) && ($latestPending || $latestRejected)))
        @if ($otherReceipts->isNotEmpty())
            <details class="learner-disclosure student-finance-receipts-disclosure">
                <summary>Submitted receipts ({{ $otherReceipts->count() }})</summary>
                <div class="student-finance-receipts-list">
                    @foreach ($otherReceipts as $item)
                        <article class="student-receipt-row" wire:key="finance-evidence-{{ $item->id }}">
                            <div>
                                <h3>{{ $item->original_name }}</h3>
                                <p>{{ $money($item->claimed_amount) }} · {{ str($item->state)->headline() }} · {{ $date($item->submitted_at) }}</p>
                                @if ($item->review_note)<p>{{ $item->review_note }}</p>@endif
                            </div>
                            <x-payment-proof-viewer :proof="$item" />
                        </article>
                    @endforeach
                </div>
            </details>
        @endif
    </section>
    @if (data_get($accountData, 'state.coverages', []) !== [])
        <section class="student-finance-records tala-task-surface" aria-labelledby="finance-coverage-heading"><h2 id="finance-coverage-heading">Fee assistance</h2>
            @foreach (data_get($accountData, 'state.coverages', []) as $coverage)<article class="student-receipt-row"><div><h3>{{ $coverage['source'] }}</h3><p>{{ str($coverage['state'])->headline() }}@if ($coverage['effective_date'] ?? null) · {{ $coverage['effective_date'] }}@endif</p></div><strong>{{ $coverage['amount'] }}</strong></article>@endforeach
        </section>
    @endif
    <p class="student-finance-secondary">Updated {{ $date($position['as_of'] ?? null) }}</p>
    @endif
</div>
