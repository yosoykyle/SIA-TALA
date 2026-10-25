<div class="student-registration">
    <div class="student-registration-heading">
        <div><h2>{{ $proposal->curriculumVersion?->program?->name ?? 'Program unavailable' }}</h2><p>{{ \App\Models\Term::typeOptions()[$enrollment->term?->type] ?? $enrollment->term?->label ?? 'Term unavailable' }} · School year {{ $enrollment->term?->academicYear?->label ?? 'unavailable' }}</p></div>
    </div>
    <div class="student-enrollment-task-layout">
    <section class="student-enrollment-task tala-task-surface" aria-label="Enrollment preparation">
        <h2>{{ $assessment && (int) $assessment->source_proposal_version_id === (int) $proposal->id ? 'Waiting for Registrar review' : 'Waiting for Registrar and Accounting' }}</h2>
        <p>The Registrar is arranging your classes.@if (! $assessment || (int) $assessment->source_proposal_version_id !== (int) $proposal->id) Accounting will prepare your fees.@endif</p>
        <p>No action is needed from you yet. You can confirm when your classes and fees are ready.</p>
        @if (($readiness['shortages'] ?? []) !== [])
            <ul class="student-registration-shortages">@foreach ($readiness['shortages'] as $shortage)<li><strong>{{ $shortage['course'] }} · {{ $shortage['section'] }}</strong><p>{{ $shortage['recovery'] }} Contact {{ $shortage['owner'] }}.</p></li>@endforeach</ul>
        @endif
        <div class="student-finance-actions"><x-filament::button wire:click="$refresh" color="gray" icon="heroicon-o-arrow-path">Refresh enrollment</x-filament::button></div>
    </section>
    @if ($assessment && (int) $assessment->source_proposal_version_id === (int) $proposal->id)
        @include('filament.student.pages.enrollment-fees')
    @endif
    </div>
    @include('filament.student.pages.enrollment-subjects', ['preparing' => true])
    @if ($schedulePresentation && $schedulePresentation['meetings'] !== [])
        <details class="learner-disclosure"><summary>Preview schedule</summary><div><p class="student-registration-help">Proposed meetings may change before confirmation.</p><x-enrollment-schedule :presentation="$schedulePresentation" :source-key="'proposal-'.$proposal->id" /></div></details>
    @endif
    @include('filament.student.pages.enrollment-help')
</div>
