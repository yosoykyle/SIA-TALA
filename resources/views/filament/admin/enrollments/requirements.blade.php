@assets
    <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
@endassets
<div class="registrar-case">
    <div class="registrar-case-identity">
        <p>{{ $case->studentProfile?->program?->name ?? $case->admissionApplication?->program?->name }}</p>
        <p>{{ $case->term?->label }}</p>
    </div>
    <section class="registrar-requirements" aria-labelledby="requirements-heading">
        <div class="registrar-requirements-heading">
            <x-filament::icon icon="heroicon-o-exclamation-circle" />
            <div><h2 id="requirements-heading">Review enrollment requirements</h2><p>Complete these checks before continuing with the selected subjects.</p></div>
        </div>
        <ul class="registrar-requirement-list">
            @foreach ($requirements as $requirement)
                <li>
                    <div><h3>{{ $requirement['title'] }}</h3><p>{{ $requirement['description'] }}</p></div>
                    @if ($requirement['url'])
                        <x-filament::button tag="a" :href="$requirement['url']" :color="$loop->first ? 'primary' : 'gray'">Review admissions</x-filament::button>
                    @elseif ($requirement['action'])
                        {{ $this->getAction($requirement['action'], isMounting: false)->color($loop->first ? 'primary' : 'gray') }}
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
    @if ($proposal !== null)
        <section class="registrar-requirement-subjects" aria-labelledby="selected-subjects-heading">
            <div class="registrar-requirement-subjects-heading"><h2 id="selected-subjects-heading">Selected subjects</h2><p>{{ $proposal->items->count() }} subjects · {{ (float) $proposal->items->sum('units_snapshot') }} units</p></div>
            @include('filament.admin.enrollments.subjects', ['sourceKey' => 'requirements'])
            @if ($schedule && $schedule['meetings'] !== [])
                <details class="registrar-case-reference"><summary>Proposed schedule</summary>
                    <x-enrollment-schedule :presentation="$schedule" :source-key="'requirements-'.$proposal->id" />
                </details>
            @endif
        </section>
    @endif
    <a class="registrar-case-history-link" href="{{ \App\Filament\Resources\Enrollments\EnrollmentResource::getUrl('view', ['record' => $case, 'view' => 'history']) }}">
        <x-filament::icon icon="heroicon-o-clock" />
        <span><strong>Enrollment history</strong><span>Activity and supporting records</span></span>
        <x-filament::icon icon="heroicon-m-chevron-right" />
    </a>
    <div class="registrar-case-progress-cancel">{{ $this->getAction('cancelCase', isMounting: false) }}</div>
</div>
