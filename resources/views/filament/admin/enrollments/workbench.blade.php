@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php($queueUrl = fn (string $key): string => \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => $key]))
<div class="registrar-workbench tala-workspace-launcher">
    <section aria-labelledby="registrar-work-heading">
        <h2 id="registrar-work-heading">Registrar work</h2>
        <p class="tala-workspace-launcher__scope">Current enrollment cases across all terms</p>
        <nav class="tala-workspace-launcher__main" aria-label="Registrar enrollment work">
            @foreach ([
                ['ready_to_prepare', 'prepare', 'heroicon-o-document-text', 'Prepare subjects', 'Drafts and subjects not yet issued', 'Prepare subjects'],
                ['placement_shortages', 'issues', 'heroicon-o-exclamation-circle', 'Resolve enrollment issues', 'Review current classes or requirements that need attention.', 'Review issues'],
                ['ready_to_finalize', 'ready', 'heroicon-o-check-circle', 'Make enrollment official', 'Confirmed and cleared for final review.', 'Review enrollments'],
            ] as [$queue, $countKey, $icon, $label, $description, $action])
                <x-workspace-card :featured="$countKey === 'prepare'" :href="$queueUrl($queue)" :icon="$icon" :label="$label" :description="$description"
                    :figure="$counts[$countKey]" :figure-label="\Illuminate\Support\Str::plural('enrollment case', $counts[$countKey])" :action="$action" :heading-level="3" />
            @endforeach
        </nav>
    </section>
    <div class="tala-workspace-launcher__split">
        <section class="registrar-start-panel tala-task-surface" aria-labelledby="registrar-start-heading">
            <h2 id="registrar-start-heading">Start enrollment</h2>
            <p>Choose who is enrolling.</p>
            <div class="registrar-start-actions">{{ $applicantAction }} {{ $continuingAction }} {{ $lateContinuingAction }}</div>
        </section>
        <section class="registrar-progress-panel tala-task-surface" aria-labelledby="registrar-progress-heading">
            <h2 id="registrar-progress-heading">Waiting on others</h2>
            <p class="tala-workspace-launcher__scope">Learner confirmation and payment review</p>
            <nav class="registrar-work-list" aria-label="Enrollments waiting for the next step">
                <x-workspace-link :href="$queueUrl('waiting_for_learner')" icon="heroicon-o-clock" label="Waiting for subject confirmation" description="The learner confirms the issued subjects.">
                    <x-slot name="status"><span class="registrar-work-count">{{ $counts['waiting'] }}</span></x-slot>
                </x-workspace-link>
                <x-workspace-link :href="$queueUrl('finance_pending')" icon="heroicon-o-banknotes" label="Waiting for payment or fee review" description="Learner payment or Accounting review is pending.">
                    <x-slot name="status"><span class="registrar-work-count">{{ $counts['finance'] }}</span></x-slot>
                </x-workspace-link>
            </nav>
        </section>
    </div>
    <div class="tala-workspace-launcher__split">
        <section class="registrar-records-entry tala-task-surface" aria-labelledby="registrar-records-heading">
            <div><h2 id="registrar-records-heading">Enrollment records</h2><p>Find an enrollment, registration certificate, or previous change.</p></div>
            <x-filament::button tag="a" :href="$queueUrl('all_cases')" color="gray" icon="heroicon-o-magnifying-glass">Find a record</x-filament::button>
        </section>
        <section class="registrar-records-entry tala-task-surface" aria-labelledby="registrar-period-heading">
            <div><h2 id="registrar-period-heading">Enrollment period</h2><p>Choose who can enroll during the published dates.</p></div>
            {{ $windowAction->button()->label('Edit enrollment period') }}
        </section>
    </div>
</div>

<x-filament-actions::modals />
