@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php($deadline = $proposal->items->map(fn ($item) => $item->reservation?->deadline)->filter()->sort()->first())
<div class="registrar-case">
    <div class="registrar-case-identity">
        @if ($case->studentProfile?->student_number)<p>{{ $case->studentProfile->student_number }}</p>@endif
        <p>{{ $proposal->curriculumVersion?->program?->name }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <section class="registrar-case-preparation" aria-labelledby="assessment-needed-heading">
        <div><h2 id="assessment-needed-heading">Assessment needed</h2><p>Review the subjects, then use the applicable approved charges.</p></div>
    </section>
    <div class="registrar-case-grid">
        <section aria-labelledby="assessment-subjects-heading">
            <h2 id="assessment-subjects-heading">Subjects</h2>
            <p class="registrar-case-meta">{{ $proposal->items->count() }} subjects · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>
            @if ($deadline)<p class="registrar-case-meta">Seats reserved until {{ \App\Support\DisplayDateTime::format($deadline, 'M j, Y · g:i A') }}.</p>@endif
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'accounting-unassessed'])
        </section>
        <section class="accounting-assessment-sources" aria-labelledby="assessment-source-heading">
            <h2 id="assessment-source-heading">Choose assessment source</h2>
            @if ($plan)
                <div><h3>Published Fee Plan</h3><p>Version {{ $plan->version }} · PHP {{ number_format((float) $plan->charges->sum('amount'), 2) }} total</p><p>Use only when the approved fixed plan represents this selection exactly.</p>
                    {{ $this->getAction('createAssessment', isMounting: false) }}
                </div>
            @else
                <div><h3>Published Fee Plan needed</h3><p>No published plan is available for this program and term.</p><x-filament::link :href="\App\Filament\Resources\FeePlans\FeePlanResource::getUrl('index')">Open Fee Plans</x-filament::link></div>
            @endif
            @if ($this->getAction('recordIndividualAssessment', isMounting: false)->isVisible())
                <div><h3>Individual assessment</h3><p>Record externally approved selection-specific charges.</p>{{ $this->getAction('recordIndividualAssessment', isMounting: false) }}</div>
            @endif
        </section>
    </div>
</div>
