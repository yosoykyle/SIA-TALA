@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
@php($queueUrl = fn (string $key): string => \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('index', ['tab' => $key]))
<div class="registrar-workbench">
    <div class="registrar-workbench-grid">
        <section class="registrar-work-panel tala-task-surface" aria-labelledby="registrar-work-heading">
            <h2 id="registrar-work-heading">Registrar work</h2>
            <nav class="registrar-work-list" aria-label="Registrar enrollment work">
                @foreach ([
                    ['ready_to_prepare', 'prepare', 'heroicon-o-document-text', 'Prepare subjects', 'Drafts and subjects not yet issued'],
                    ['placement_shortages', 'issues', 'heroicon-o-exclamation-circle', 'Resolve enrollment issues', 'Current classes or requirements that need attention'],
                    ['ready_to_finalize', 'ready', 'heroicon-o-check-circle', 'Make enrollment official', 'Confirmed and cleared for final review'],
                ] as [$queue, $countKey, $icon, $label, $description])
                    <a href="{{ $queueUrl($queue) }}" class="registrar-work-row">
                        <x-filament::icon :icon="$icon" class="registrar-work-icon" />
                        <span><strong>{{ $label }}</strong><span class="registrar-work-description">{{ $description }}</span></span>
                        <span class="registrar-work-count">{{ $counts[$countKey] }}</span>
                        <x-filament::icon icon="heroicon-m-chevron-right" class="registrar-work-arrow" />
                    </a>
                @endforeach
            </nav>
        </section>
        <section class="registrar-start-panel tala-task-surface" aria-labelledby="registrar-start-heading">
            <h2 id="registrar-start-heading">Start enrollment</h2>
            <p>Choose who is enrolling.</p>
            <div class="registrar-start-actions">{{ $applicantAction }} {{ $continuingAction }} {{ $lateContinuingAction }}</div>
        </section>
    </div>
    <section class="registrar-progress-panel tala-task-surface" aria-labelledby="registrar-progress-heading">
        <h2 id="registrar-progress-heading">In progress</h2>
        <nav class="registrar-work-list" aria-label="Enrollments waiting for the next step">
            <a href="{{ $queueUrl('waiting_for_learner') }}" class="registrar-work-row">
                <x-filament::icon icon="heroicon-o-clock" class="registrar-work-icon" />
                <span>Waiting for subject confirmation</span><span class="registrar-work-count">{{ $counts['waiting'] }}</span>
                <x-filament::icon icon="heroicon-m-chevron-right" class="registrar-work-arrow" />
            </a>
            <a href="{{ $queueUrl('finance_pending') }}" class="registrar-work-row">
                <x-filament::icon icon="heroicon-o-banknotes" class="registrar-work-icon" />
                <span>Waiting for payment or fee review</span><span class="registrar-work-count">{{ $counts['finance'] }}</span>
                <x-filament::icon icon="heroicon-m-chevron-right" class="registrar-work-arrow" />
            </a>
        </nav>
    </section>
    <section class="registrar-records-entry tala-task-surface" aria-labelledby="registrar-records-heading">
        <div><h2 id="registrar-records-heading">Enrollment records</h2><p>Find an enrollment, registration certificate, or previous change.</p></div>
        <x-filament::button tag="a" :href="$queueUrl('all_cases')" color="gray" icon="heroicon-o-magnifying-glass">Find a record</x-filament::button>
    </section>
    <section class="registrar-records-entry tala-task-surface" aria-labelledby="registrar-period-heading">
        <div><h2 id="registrar-period-heading">Enrollment period</h2><p>Choose who can enroll during the published dates.</p></div>
        {{ $windowAction->button()->label('Edit enrollment period') }}
    </section>
</div>

<x-filament-actions::modals />
