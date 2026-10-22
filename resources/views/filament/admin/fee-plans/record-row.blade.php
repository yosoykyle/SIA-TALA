@php
    $plan = $getRecord();
    $readiness = \App\Filament\Resources\FeePlans\Tables\FeePlansTable::draftReadiness($plan);
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
    $statusDateLabel = $plan->state === \App\Models\FeePlan::StatePublished ? 'Published' : ($plan->state === \App\Models\FeePlan::StateDraft ? 'Created' : 'Updated');
    $money = fn ($value) => $value === null ? 'Not set' : 'PHP '.number_format((float) $value, 2);
@endphp
<article class="accounting-fee-plan-record">
    <div class="accounting-fee-plan-identity">
        <div class="accounting-fee-plan-heading"><h2>{{ $plan->program?->name }}</h2><x-filament::badge :color="$draft ? 'warning' : ($plan->state === \App\Models\FeePlan::StatePublished ? 'success' : 'gray')">{{ $status }}</x-filament::badge></div>
        <p>{{ $plan->term?->label }}</p><p class="accounting-fee-plan-meta">{{ $statusDateLabel }} {{ $statusDate?->timezone(config('app.display_timezone'))->format('j M Y') ?? 'date unavailable' }}</p>
    </div>
    <dl class="accounting-fee-plan-amounts"><div><dt>Total charges</dt><dd>{{ $money($plan->charges_sum_amount) }}</dd></div><div><dt>Enrollment payment</dt><dd>{{ $money($plan->enrollment_payment_amount) }}</dd></div></dl>
    @if ($draft)<p class="accounting-fee-plan-readiness">{{ $readiness ?? 'Ready for approval' }}</p>@endif
    <x-filament::button tag="a" :href="\App\Filament\Resources\FeePlans\FeePlanResource::getUrl('view', ['record' => $plan])" color="gray">{{ $draft ? 'Continue draft' : 'Open fee plan' }}<span class="sr-only"> for {{ $plan->program?->name }}, {{ $plan->term?->label }}, {{ $status }}</span></x-filament::button>
</article>
