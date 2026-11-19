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
        <div><h2 id="assessment-needed-heading">Needs fee assessment</h2><p>Check the subjects below, then apply the standard fee plan or enter custom charges.</p></div>
    </section>
    <div class="registrar-case-grid accounting-unassessed-grid">
        <section class="accounting-assessment-sources" aria-labelledby="assessment-source-heading">
            <h2 id="assessment-source-heading">Choose how to assess this student</h2>
            <div class="accounting-assessment-sources__grid">
                @if ($plan)
                    <article class="accounting-assessment-source-card">
                        <div class="accounting-assessment-source-card__body">
                            <h3>Standard fee plan</h3>
                            <p class="accounting-assessment-source-card__summary">{{ \App\Actions\Finance\AccountingDisplay::money($plan->charges->sum('amount')) }} total{{ $plan->published_at ? ' · Updated '.\App\Actions\Finance\AccountingDisplay::date($plan->published_at) : '' }}</p>
                            <p>Use the approved program fees and payment dates for regular full-load enrollments.</p>
                        </div>
                        <div class="tala-workspace-feature__action">
                            {{ $this->getAction('createAssessment', isMounting: false) }}
                        </div>
                    </article>
                @else
                    <article class="accounting-assessment-source-card">
                        <div class="accounting-assessment-source-card__body">
                            <h3>No standard fee plan yet</h3>
                            <p>Set up and publish a fee plan for this program and term first, or use a custom assessment for an approved exception.</p>
                        </div>
                        <div class="tala-workspace-feature__action">
                            <x-filament::link :href="$feePlanUrl">Open Fee Plans</x-filament::link>
                        </div>
                    </article>
                @endif
                @if ($this->getAction('recordIndividualAssessment', isMounting: false)->isVisible())
                    <article class="accounting-assessment-source-card">
                        <div class="accounting-assessment-source-card__body">
                            <h3>Custom assessment</h3>
                            <p>Enter approved custom charges or payment dates for reduced load, overload, or special terms.</p>
                        </div>
                        <div class="tala-workspace-feature__action">
                            {{ $this->getAction('recordIndividualAssessment', isMounting: false) }}
                        </div>
                    </article>
                @endif
            </div>
        </section>
        <section aria-labelledby="assessment-subjects-heading">
            <header class="accounting-subjects-head">
                <div class="accounting-subjects-head__lead">
                    <h2 id="assessment-subjects-heading">Subjects</h2>
                    <p class="accounting-subjects-head__count">{{ $proposal->items->count() }} {{ \Illuminate\Support\Str::plural('subject', $proposal->items->count()) }} · {{ (float) $proposal->items->sum('units_snapshot') }} units</p>
                </div>
                @if ($deadline)
                    <dl class="accounting-subjects-head__metrics">
                        <div>
                            <dt>Seats reserved until</dt>
                            <dd>{{ \App\Actions\Finance\AccountingDisplay::dateTime($deadline) }}</dd>
                        </div>
                    </dl>
                @endif
            </header>
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'accounting-unassessed'])
        </section>
    </div>
</div>
