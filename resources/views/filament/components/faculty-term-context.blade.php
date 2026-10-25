@if (! $term)
<section class="tala-faculty-term-chooser" aria-labelledby="faculty-term-chooser-heading">
    <x-heroicon-o-calendar-days aria-hidden="true" />
    <h2 id="faculty-term-chooser-heading">{{ $emptyHeading ?? 'Choose a Term' }}</h2>
    <p>{{ $terms->isEmpty() ? 'No Term is available. Contact the Registrar.' : ($emptyDescription ?? 'Select an Academic Year and Term to continue.') }}</p>
    @if ($terms->isNotEmpty())
        <div class="tala-term-picker">@include('filament.components.faculty-term-select')</div>
    @endif
</section>
@else
<header class="tala-planning-context tala-faculty-term-context">
    <div class="tala-faculty-scope">
    <div class="tala-term-picker">@include('filament.components.faculty-term-select', ['termSelectorLabel' => 'Academic Year and Term'])</div>
    @if ($contextStatus ?? null)
        <p class="tala-term-context-links text-sm" role="status">
            {{ $contextStatus }}
            @if ($syntheticReview ?? false)
                · Synthetic review data
            @endif
        </p>
    @endif
    </div>
@if ($showExaminationContext ?? false)<div class="tala-faculty-examination-context text-sm">
    <p><strong>Examination period: {{ $examinationPeriod ? $examinationPeriod->opens_on->format('M j').'–'.$examinationPeriod->closes_on->format('M j, Y') : 'Dates not configured' }}</strong>@unless ($examinationPeriod) · Contact the Registrar for approved dates.@endunless</p>
    @if ($examinationPeriod)<details><summary>Examination source details</summary>
<div class="tala-planning-reveal"><p>{{ $examinationSource->authority_reference }} · Calendar v{{ $examinationSource->version }} · Registrar · As of {{ $asOf }} (Asia/Manila)</p><p>Arrange exact class examinations with your students.</p>
</div>
</details>@endif
</div>@endif
</header>
@endif
