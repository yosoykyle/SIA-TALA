@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css').'?v='.filemtime(public_path('css/tala-enrollment.css')) }}">
@endassets
@php
    $deadline = $proposal->items->map(fn ($item) => $item->reservation?->deadline)->filter()->sort()->first();
    $programId = $case->currentProposalVersion?->curriculumVersion?->program_id ?? $case->studentProfile?->program_id ?? $case->admissionApplication?->program_id;
    $feePlanFilters = array_filter([
        'term' => $case->term_id ? ['value' => $case->term_id] : null,
        'program' => $programId ? ['value' => $programId] : null,
    ]);
    $feePlanUrl = \App\Filament\Resources\FeePlans\FeePlanResource::getUrl('index', $feePlanFilters !== [] ? ['filters' => $feePlanFilters] : []);
@endphp
<div class="registrar-case">
    <section @class(['registrar-case-preparation', 'tala-task-surface' => ! $this->isAccountingWorkspace()]) aria-labelledby="assessment-needed-heading">
        <div><h2 id="assessment-needed-heading">Assessment needed</h2><p>Review the subjects, then use the applicable approved charges.</p></div>
    </section>
    <div class="registrar-case-grid">
        <section aria-labelledby="assessment-subjects-heading">
            <h2 id="assessment-subjects-heading">Subjects</h2>
            <p class="registrar-case-meta">{{ $proposal->items->count() }} subjects · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>
            @if ($deadline)<p class="registrar-case-meta">Seats reserved until {{ \App\Actions\Finance\AccountingDisplay::dateTime($deadline) }}.</p>@endif
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'accounting-unassessed'])
        </section>
        <section class="accounting-assessment-sources" aria-labelledby="assessment-source-heading">
            <h2 id="assessment-source-heading">Choose assessment source</h2>
            @if ($plan)
                <div><h3>Published Fee Plan</h3><p>Version {{ $plan->version }} · {{ \App\Actions\Finance\AccountingDisplay::money($plan->charges->sum('amount')) }} total</p><p>Use for standard enrollments when the fixed program charges and payment due dates match this enrollment.</p>
                    {{ $this->getAction('createAssessment', isMounting: false) }}
                </div>
            @else
                <div><h3>Published Fee Plan needed</h3><p>No published Fee Plan exists for this program and term yet. Publish a Fee Plan for standard charges, or use Individual assessment below for an approved exception.</p><x-filament::link :href="$feePlanUrl">Open Fee Plans</x-filament::link></div>
            @endif
            @if ($this->getAction('recordIndividualAssessment', isMounting: false)->isVisible())
                <div><h3>Individual assessment</h3><p>Use for approved exceptions—such as reduced load, overload, special term, or custom payment dates—instead of the standard Fee Plan.</p>{{ $this->getAction('recordIndividualAssessment', isMounting: false) }}</div>
            @endif
        </section>
    </div>
</div>
