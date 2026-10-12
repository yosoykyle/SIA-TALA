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
    @if ($examinationPeriod)<details><summary>Examination source details</summary><p>{{ $examinationSource->authority_reference }} · Calendar v{{ $examinationSource->version }} · Registrar · As of {{ $asOf }} (Asia/Manila)</p><p>Arrange exact class examinations with your students.</p></details>@endif
</div>@endif
</header>
