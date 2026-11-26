@include('filament.admin.fee-plans.styles')
@php
    $draft = $plan->state === \App\Models\FeePlan::StateDraft;
    $superseded = $plan->state === \App\Models\FeePlan::StateSuperseded;
    $status = \App\Actions\Finance\AccountingDisplay::feePlanStatusLabel($plan->state);
    $statusColor = \App\Actions\Finance\AccountingDisplay::feePlanStatusColor($plan->state);
    $currentPlan = $versions->firstWhere('state', \App\Models\FeePlan::StatePublished);
    $totalCharges = $plan->charges->sum('amount');
    $enrollmentPayment = $plan->obligations->where('required_for_enrollment', true)->sum('amount');
    $chargeCount = $plan->charges->count();
    $paymentCount = $plan->obligations->count();
@endphp
<div class="accounting-fee-plan-detail">
    <section class="accounting-fee-plan-summary tala-task-surface" aria-label="Fee plan summary">
        <div class="accounting-fee-plan-summary__status">
            <div class="accounting-fee-plan-summary__badges">
                <x-filament::badge :color="$statusColor">{{ $status }}</x-filament::badge>
                @if ($plan->published_at)
                    <span class="accounting-fee-plan-meta">Last updated {{ \App\Actions\Finance\AccountingDisplay::dateTime($plan->published_at, 'M j, Y') }}</span>
                @elseif ($plan->supersedes)
                    <span class="accounting-fee-plan-meta">Updates current plan</span>
                @endif
            </div>
            @if ($draft && $readiness)
                <p class="accounting-fee-plan-summary__message is-blocker">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                    <span>{{ $readiness }}</span>
                </p>
            @elseif ($draft)
                <p class="accounting-fee-plan-summary__message is-ready">
                    <x-filament::icon icon="heroicon-o-check-circle" aria-hidden="true" />
                    <span>Ready for approval. Charges and payment dates balance.</span>
                </p>
            @elseif ($superseded)
                <p class="accounting-fee-plan-summary__message">Replaced by a newer plan. Existing assessments stay unchanged.</p>
            @else
                <p class="accounting-fee-plan-summary__message">Current for new assessments in this program and term. Existing assessments stay unchanged.</p>
            @endif
            @if ($currentPlan && $currentPlan->id !== $plan->id)
                <div class="accounting-fee-plan-summary__link">
                    <x-filament::link icon="heroicon-m-arrow-right" icon-position="after" :href="\App\Filament\Resources\FeePlans\FeePlanResource::getUrl('view', ['record' => $currentPlan])">Open current plan</x-filament::link>
                </div>
            @endif
        </div>
        <dl class="accounting-fee-plan-summary__metrics">
            <div>
                <dt>Total charges</dt>
                <dd>{{ \App\Actions\Finance\AccountingDisplay::money($totalCharges) }}</dd>
            </div>
            <div>
                <dt>Enrollment payment</dt>
                <dd>{{ \App\Actions\Finance\AccountingDisplay::money($enrollmentPayment) }}</dd>
            </div>
            <div>
                <dt>Schedule</dt>
                <dd>{{ $paymentCount }} {{ str('payment')->plural($paymentCount) }} · {{ $chargeCount }} {{ str('charge')->plural($chargeCount) }}</dd>
            </div>
        </dl>
    </section>

    <div class="accounting-fee-plan-workspace">
        <div class="accounting-fee-plan-main">
            <section class="accounting-fee-plan-panel tala-task-surface" aria-labelledby="fee-plan-charges">
                <div class="accounting-fee-plan-panel__header">
                    <h2 id="fee-plan-charges">Charges</h2>
                    <span class="accounting-fee-plan-meta">{{ $chargeCount }} {{ str('item')->plural($chargeCount) }}</span>
                </div>
                <x-data-table label="Fee plan charges" class="accounting-fee-plan-table-wrap">
                    <thead>
                        <tr>
                            <th scope="col">Charge</th>
                            <th scope="col" class="tala-data-table-number">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($plan->charges as $charge)
                            <tr>
                                <th scope="row">
                                    {{ $charge->label }}
                                    @if (filled($charge->category))
                                        <span class="tala-data-table-secondary">{{ \Illuminate\Support\Str::of($charge->category)->headline()->lower()->ucfirst() }}</span>
                                    @endif
                                </th>
                                <td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($charge->amount) }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="2" class="accounting-fee-plan-table-empty">No charges recorded. Edit the draft to add charges.</td>
                            </tr>
                        @endforelse
                    </tbody>
                    @if ($plan->charges->isNotEmpty())
                        <tfoot>
                            <tr class="accounting-fee-plan-table-total">
                                <th scope="row">Total term charges</th>
                                <td class="tala-data-table-number">{{ \App\Actions\Finance\AccountingDisplay::money($totalCharges) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </x-data-table>
            </section>

            <section class="accounting-fee-plan-panel tala-task-surface" aria-labelledby="fee-plan-payments">
                <div class="accounting-fee-plan-panel__header">
                    <h2 id="fee-plan-payments">Payment schedule</h2>
                </div>
                <ol class="accounting-fee-plan-payments">
                    @forelse ($plan->obligations as $payment)
                        @php
                            $dueAt = $payment->due_at?->timezone(config('app.display_timezone', 'Asia/Manila'));
                        @endphp
                        <li @class(['is-enrollment-required' => $payment->required_for_enrollment])>
                            <div class="accounting-fee-plan-date">
                                @if ($dueAt)
                                    <time datetime="{{ $payment->due_at->toIso8601String() }}">
                                        <span>{{ $dueAt->format('M') }}</span>
                                        <strong>{{ $dueAt->format('j') }}</strong>
                                    </time>
                                @else
                                    <span class="accounting-fee-plan-date-missing">TBD</span>
                                @endif
                            </div>
                            <div class="accounting-fee-plan-payment-description">
                                <div class="accounting-fee-plan-payment-title">
                                    <h3>{{ $payment->label }}</h3>
                                    @if ($payment->required_for_enrollment)
                                        <span class="accounting-fee-plan-required">Required for enrollment</span>
                                    @endif
                                </div>
                                @if ($dueAt)
                                    <p>{{ \App\Actions\Finance\AccountingDisplay::dateTime($payment->due_at) }}</p>
                                @else
                                    <p>Payment date not recorded.</p>
                                @endif
                            </div>
                            <strong class="accounting-fee-plan-payment-amount">{{ \App\Actions\Finance\AccountingDisplay::money($payment->amount) }}</strong>
                        </li>
                    @empty
                        <li class="accounting-fee-plan-empty">{{ $draft ? 'No payment dates recorded. Edit the draft to add payments.' : 'No payment dates are recorded for this plan.' }}</li>
                    @endforelse
                </ol>
            </section>
        </div>

        <aside class="accounting-fee-plan-rail" aria-label="Publication details and plan history">
            @if (! $draft)
                <section class="accounting-fee-plan-panel accounting-fee-plan-approval tala-task-surface" aria-labelledby="fee-plan-approval">
                    <h2 id="fee-plan-approval">Publication details</h2>
                    <dl class="accounting-fee-plan-kv">
                        <div>
                            <dt>Published by</dt>
                            <dd>{{ $plan->publisher?->getFilamentName() ?? 'Accounting' }}</dd>
                        </div>
                        <div>
                            <dt>Published on</dt>
                            <dd>{{ $plan->published_at ? \App\Actions\Finance\AccountingDisplay::dateTime($plan->published_at) : 'Not recorded' }}</dd>
                        </div>
                        @if (filled($plan->authority_reference))
                            <div>
                                <dt>Approval note / memo</dt>
                                <dd>{{ $plan->authority_reference }}</dd>
                            </div>
                        @endif
                        @if ($plan->authority_date)
                            <div>
                                <dt>Approved on</dt>
                                <dd>{{ \App\Actions\Finance\AccountingDisplay::dateTime($plan->authority_date, 'M j, Y') }}</dd>
                            </div>
                        @endif
                        @if (filled($plan->no_payment_required_authority_reference))
                            <div>
                                <dt>Zero-payment approval</dt>
                                <dd>{{ $plan->no_payment_required_authority_reference }}</dd>
                            </div>
                        @endif
                    </dl>
                </section>
            @endif

            <section class="accounting-fee-plan-panel tala-task-surface" aria-labelledby="fee-plan-versions-heading">
                <div class="accounting-fee-plan-panel__header">
                    <h2 id="fee-plan-versions-heading">Plan history</h2>
                    <span class="accounting-fee-plan-meta">{{ $versions->count() }} {{ str('entry')->plural($versions->count()) }}</span>
                </div>
                <ul class="accounting-fee-plan-versions">
                    @foreach ($versions as $version)
                        @php
                            $versionHeading = match ($version->state) {
                                \App\Models\FeePlan::StatePublished => 'Current',
                                \App\Models\FeePlan::StateDraft => 'Draft',
                                default => 'Past',
                            };
                            $versionDate = $version->published_at
                                ? 'Published '.\App\Actions\Finance\AccountingDisplay::dateTime($version->published_at, 'M j, Y')
                                : ($version->updated_at ? 'Updated '.\App\Actions\Finance\AccountingDisplay::dateTime($version->updated_at, 'M j, Y') : null);
                        @endphp
                        <li @class(['is-current' => $version->id === $plan->id])>
                            @if ($version->id === $plan->id)
                                <div aria-current="page" class="accounting-fee-plan-version-card accounting-fee-plan-version-current">
                                    <div class="accounting-fee-plan-version-card__head">
                                        <strong>{{ $versionHeading }}</strong>
                                        <x-filament::badge size="sm" color="success">Viewing</x-filament::badge>
                                    </div>
                                    <p class="accounting-fee-plan-version-card__meta">{{ $versionDate ?? 'Saved' }}</p>
                                </div>
                            @else
                                <a class="accounting-fee-plan-version-card" {{ \Filament\Support\generate_href_html(\App\Filament\Resources\FeePlans\FeePlanResource::getUrl('view', ['record' => $version])) }}>
                                    <div class="accounting-fee-plan-version-card__head">
                                        <strong>{{ $versionHeading }}</strong>
                                        <span class="accounting-fee-plan-version-card__open">
                                            <span>Open</span>
                                            <x-filament::icon icon="heroicon-m-chevron-right" />
                                        </span>
                                    </div>
                                    <p class="accounting-fee-plan-version-card__meta">{{ $versionDate ?? 'Saved' }}</p>
                                </a>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        </aside>
    </div>
</div>
