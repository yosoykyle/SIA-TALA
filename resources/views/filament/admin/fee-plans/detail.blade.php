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
    $statusDate = match ($plan->state) {
        \App\Models\FeePlan::StatePublished => $plan->published_at,
        \App\Models\FeePlan::StateDraft => $plan->created_at,
        default => $plan->updated_at,
    };
@endphp
<div class="accounting-fee-plan-detail">
    <div class="accounting-fee-plan-intro"><x-filament::badge :color="$draft ? 'warning' : ($plan->state === \App\Models\FeePlan::StatePublished ? 'success' : 'gray')">{{ $status }}</x-filament::badge><p>{{ $draft ? ($readiness ?? 'Amounts and payment dates are ready for approval.') : ($plan->state === \App\Models\FeePlan::StateSuperseded ? 'Superseded on '.$date($statusDate).'. The current plan is used for new assessments. Existing assessments keep their recorded plan.' : 'Used for new assessments since '.$date($statusDate).'. Existing assessments keep their recorded plan.') }}</p></div>
    <div class="accounting-fee-plan-layout">
        <section aria-labelledby="fee-plan-charges"><h2 id="fee-plan-charges">Charges</h2><table><thead><tr><th scope="col">Charge</th><th scope="col">Amount (PHP)</th></tr></thead><tbody>@forelse ($plan->charges as $charge)<tr><th scope="row">{{ $charge->label }}</th><td>{{ $money($charge->amount) }}</td></tr>@empty<tr><td colspan="2">No charges recorded. Edit the draft to add charges.</td></tr>@endforelse</tbody><tfoot><tr><th scope="row">Total charges</th><td>{{ $money($plan->charges->sum('amount')) }}</td></tr></tfoot></table></section>
        <section aria-labelledby="fee-plan-payments"><h2 id="fee-plan-payments">Payment dates</h2><p class="accounting-fee-plan-meta">Dates use Asia/Manila.</p><div class="accounting-fee-plan-payments">@forelse ($plan->obligations as $payment)<div><div><h3>{{ $payment->label }}</h3>@if ($payment->required_for_enrollment)<p>Required for enrollment</p>@endif<p>{{ $date($payment->due_at) }}</p></div><strong>PHP {{ $money($payment->amount) }}</strong></div>@empty<p>No payment dates recorded. Edit the draft to add payments.</p>@endforelse</div></section>
    </div>
    @if (!$draft)<section class="accounting-fee-plan-approval" aria-labelledby="fee-plan-approval"><h2 id="fee-plan-approval">Approval</h2><dl><div><dt>Approval reference</dt><dd>{{ $plan->authority_reference }}</dd></div><div><dt>Approved</dt><dd>{{ $plan->authority_date?->format('j M Y') }}</dd></div><div><dt>Published</dt><dd>{{ $date($plan->published_at) }} · Asia/Manila</dd></div>@if (filled($plan->no_payment_required_authority_reference))<div><dt>Zero-payment approval</dt><dd>{{ $plan->no_payment_required_authority_reference }}</dd></div>@endif</dl></section>@endif
    <details class="accounting-fee-plan-source"><summary>Source details and version history</summary><h3>Charge sources</h3><dl>@foreach ($plan->charges as $charge)<div><dt>{{ $charge->label }}</dt><dd>{{ $charge->code }}@if (filled($charge->category)) · {{ $charge->category }}@endif</dd></div>@endforeach</dl><h3>Payment sources</h3><dl>@foreach ($plan->obligations as $payment)<div><dt>{{ $payment->label }}</dt><dd>{{ $payment->code }} · {{ $payment->purpose }}</dd></div>@endforeach</dl><h3>Versions for this program and term</h3><ul>@foreach ($versions as $version)<li>@if ($version->id === $plan->id)<span>Version {{ $version->version }} · {{ $version->state }} · This version</span>@else<a href="{{ \App\Filament\Resources\FeePlans\FeePlanResource::getUrl('view', ['record' => $version]) }}">Version {{ $version->version }} · {{ $version->state }}</a>@endif</li>@endforeach</ul></details>
</div>
