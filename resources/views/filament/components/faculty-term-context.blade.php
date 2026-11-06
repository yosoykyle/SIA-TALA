@if (! $term)
<section class="tala-record-picker servitech-journey-scene" aria-labelledby="faculty-term-chooser-heading">
    <x-servitech-journey-scenery />
    <div class="servitech-picker-content">
    <header class="tala-record-picker-heading">
    <x-filament::icon icon="heroicon-o-calendar-days" class="tala-record-picker-icon" aria-hidden="true" />
    <h2 id="faculty-term-chooser-heading">{{ $emptyHeading ?? 'Choose a Term' }}</h2>
    <p>{{ $terms->isEmpty() ? 'No Term is available. Contact the Registrar.' : ($emptyDescription ?? 'Select an Academic Year and Term to continue.') }}</p>
    </header>
    @if ($terms->isNotEmpty())
        <div class="tala-term-picker">@include('filament.components.faculty-term-select')</div>
    @endif
    </div>
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
    @if ($examinationPeriod)<x-supporting-details summary="Examination source details" inset>
        <p>{{ $examinationSource->authority_reference }} · Calendar v{{ $examinationSource->version }} · Registrar · As of {{ $asOf }} (Asia/Manila)</p><p>Arrange exact class examinations with your students.</p>
    </x-supporting-details>@endif
</div>@endif
</header>
@endif
