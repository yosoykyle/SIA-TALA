@include('filament.admin.fee-plans.styles')
@php
    $money = fn ($amount) => number_format((float) $amount, 2);
    $date = fn ($value) => $value?->timezone('Asia/Manila')->format('j M Y, g:i A');
    $draft = $plan->state === \App\Models\FeePlan::StateDraft;
    $status = match ($plan->state) {
        \App\Models\FeePlan::StatePublished => 'Current plan',
        \App\Models\FeePlan::StateDraft => 'Draft',
        default => 'Superseded plan',
    };
@endphp
<div class="accounting-fee-plan-detail">
    <div class="accounting-fee-plan-intro">
        <x-filament::badge :color="$draft ? 'warning' : ($plan->state === \App\Models\FeePlan::StatePublished ? 'success' : 'gray')">{{ $status }}</x-filament::badge>
        <p>{{ $draft ? ($readiness ?? 'Ready for approval.') : ($plan->state === \App\Models\FeePlan::StateSuperseded ? 'Replaced by a newer plan. Existing assessments stay unchanged.' : 'Used for new charges. Existing assessments stay unchanged.') }}</p>
    </div>
    <div class="accounting-fee-plan-layout">
        <section class="accounting-fee-plan-panel tala-task-surface" aria-labelledby="fee-plan-charges">
            <h2 id="fee-plan-charges">Charges</h2>
            <div class="accounting-fee-plan-total">
                <span>Total term charges</span>
                <strong>PHP {{ $money($plan->charges->sum('amount')) }}</strong>
            </div>
            <div class="accounting-fee-plan-table-wrap">
                <table>
                    <thead><tr><th scope="col">Charge</th><th scope="col">Amount (PHP)</th></tr></thead>
                    <tbody>
                        @forelse ($plan->charges as $charge)
                            <tr><th scope="row">{{ $charge->label }}</th><td>{{ $money($charge->amount) }}</td></tr>
                        @empty
                            <tr><td colspan="2">No charges recorded. Edit the draft to add charges.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="accounting-fee-plan-panel tala-task-surface" aria-labelledby="fee-plan-payments">
            <div class="accounting-fee-plan-section-heading">
                <h2 id="fee-plan-payments">Payment schedule</h2>
                <p class="accounting-fee-plan-meta">Dates use Asia/Manila.</p>
            </div>
            <ol class="accounting-fee-plan-payments">
                @forelse ($plan->obligations as $payment)
                    @php($dueAt = $payment->due_at?->timezone('Asia/Manila'))
                    <li @class(['is-enrollment-required' => $payment->required_for_enrollment])>
                        <div class="accounting-fee-plan-date">
                            @if ($dueAt)
                                <time datetime="{{ $payment->due_at->toIso8601String() }}">
                                    <span>{{ $dueAt->format('M') }}</span>
                                    <strong>{{ $dueAt->format('j') }}</strong>
                                    <span>{{ $dueAt->format('Y') }}</span>
                                </time>
                            @else
                                <span class="accounting-fee-plan-date-missing">Date<br>not set</span>
                            @endif
                        </div>
                        <div class="accounting-fee-plan-payment-description">
                            <h3>{{ $payment->label }}</h3>
                            @if ($payment->required_for_enrollment)
                                <span class="accounting-fee-plan-required">Required for enrollment</span>
                            @endif
                            @if ($dueAt)
                                <p>{{ $dueAt->format('j M Y, g:i A') }} · Asia/Manila</p>
                            @else
                                <p>Payment date not recorded.</p>
                            @endif
                        </div>
                        <strong class="accounting-fee-plan-payment-amount">PHP {{ $money($payment->amount) }}</strong>
                    </li>
                @empty
                    <li class="accounting-fee-plan-empty">{{ $draft ? 'No payment dates recorded. Edit the draft to add payments.' : 'No payment dates are recorded for this plan.' }}</li>
                @endforelse
            </ol>
        </section>
    </div>
    <div class="accounting-fee-plan-support tala-task-surface">
    @if (!$draft)
        <section class="accounting-fee-plan-approval" aria-labelledby="fee-plan-approval">
            <h2 id="fee-plan-approval"><x-filament::icon icon="heroicon-o-check-badge" aria-hidden="true" />Approval</h2>
            <dl>
                <div><dt>Approval reference</dt><dd>{{ $plan->authority_reference }}</dd></div>
                <div><dt>Approved</dt><dd>{{ $plan->authority_date?->format('j M Y') }}</dd></div>
                <div><dt>Published</dt><dd>{{ $date($plan->published_at) }} · Asia/Manila</dd></div>
                @if (filled($plan->no_payment_required_authority_reference))
                    <div><dt>Zero-payment approval</dt><dd>{{ $plan->no_payment_required_authority_reference }}</dd></div>
                @endif
            </dl>
        </section>
    @endif
    <details class="accounting-fee-plan-source">
        <summary>Source details and version history</summary>
        <div class="accounting-fee-plan-source-content">
        <h3>Charge sources</h3>
        <dl>
            @foreach ($plan->charges as $charge)
                <div><dt>{{ $charge->label }}</dt><dd>{{ $charge->code }}@if (filled($charge->category)) · {{ $charge->category }}@endif</dd></div>
            @endforeach
        </dl>
        <h3>Payment sources</h3>
        <dl>
            @foreach ($plan->obligations as $payment)
                <div><dt>{{ $payment->label }}</dt><dd>{{ $payment->code }} · {{ $payment->purpose }}</dd></div>
            @endforeach
        </dl>
        <h3>Versions for this program and term</h3>
        <ul>
            @foreach ($versions as $version)
                <li>
                    @if ($version->id === $plan->id)
                        <span>Version {{ $version->version }} · {{ $version->state }} · This version</span>
                    @else
                        <a href="{{ \App\Filament\Resources\FeePlans\FeePlanResource::getUrl('view', ['record' => $version]) }}">Version {{ $version->version }} · {{ $version->state }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
        </div>
    </details>
    </div>
</div>
