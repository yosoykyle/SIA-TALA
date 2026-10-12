<x-filament-panels::page>

    @include('filament.pages.partials.term-workspace-header')

    @if ($term)

        <section class="tala-term-state" aria-label="Current Term state">
            <dl class="tala-state-ledger">
                <div><dt>Teaching calendar</dt><dd><x-filament::link :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">{{ $taskSummaries['overview'] }}</x-filament::link></dd></div>
                <div><dt>Official timetable</dt><dd>@if ($currentVersion)<x-filament::link :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'selectedVersionId' => $currentVersion->id])">Official v{{ $currentVersion->version }}</x-filament::link>@else Not published @endif</dd></div>
                <div><dt>Latest draft</dt><dd>@if ($activeRun)<x-filament::link :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'selectedRunId' => $activeRun->id])">{{ \App\Models\ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? $activeRun->status }}</x-filament::link>@else No draft recorded @endif</dd></div>
            </dl>
        </section>
        <section class="tala-task-work" aria-labelledby="term-programs-heading">
            <div class="tala-task-heading">
                <div><h2 id="term-programs-heading">Programs & classes</h2><p>{{ $this->coverage['summary']['program_count'] }} Programs with classes · {{ $this->coverage['summary']['section_count'] }} student sections · {{ $this->coverage['summary']['class_count'] }} unique classes · {{ $this->coverage['summary']['meeting_count'] }} weekly meetings</p></div>
                <div class="tala-task-commands">
                @if ($activeRun)<x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'selectedRunId' => $activeRun->id])">{{ $readOnly ? 'View draft evidence' : 'Review draft' }}</x-filament::button>
                @elseif (! $readOnly && $this->generationReadiness()['ready']){{ $this->generateTimetableAction }}@endif
            </div>
        </div>
        @if (! $this->generationReadiness()['ready'])
            @php($recoveryTab = $this->generationReadiness()['tab'] ?? 'overview')
            <div class="tala-task-finding" role="status"><p>{{ $this->generationReadiness()['reason'] }}</p><x-filament::link :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $recoveryTab])">{{ match ($recoveryTab) { 'classes' => 'Review classes and meeting requirements', 'resources' => 'Review Faculty and rooms', 'generate' => 'View timetable progress', default => 'Review teaching calendar' } }}</x-filament::link></div>
        @endif
        {{ $this->table }}
        <p class="tala-work-footnote">Generation covers the whole recorded ready Term. Shared classes count once; years without classes remain advisory.</p>
        </section>

        @if ($this->coverage['additional'])

            <x-filament::section><x-slot name="heading">Additional classes and sections needing association</x-slot><x-slot name="description">Retakes, electives and mixed-year associations remain part of planning. These labels do not classify a student.</x-slot>

                <ul class="space-y-4">@foreach ($this->coverage['additional'] as $row)<li><strong>{{ $row['program_name'] }}{{ $row['year_level'] ? ' · Year '.$row['year_level'] : '' }}</strong><p class="text-sm">{{ implode(', ', $row['section_references']) ?: 'No section linked' }} · {{ $row['class_count'] }} classes · {{ $row['meeting_count'] }} weekly meetings</p><p class="text-sm text-gray-600 dark:text-gray-300">{{ $row['reason'] }}</p></li>@endforeach</ul>

            </x-filament::section>

        @endif

    @else

        <x-filament::section><x-slot name="heading">{{ $readOnly ? 'Choose the Term to inspect' : 'Choose the Term to plan' }}</x-slot><x-slot name="description">{{ $readOnly ? 'Inspect the selected Term’s recorded programs, classes and shared resources.' : 'Start or resume one Term. Its programs, classes and shared resources will appear together.' }}</x-slot>

            <div class="divide-y divide-gray-200 dark:divide-white/10">@foreach ($terms->take(6) as $availableTerm)<a class="tala-planning-record-link" href="{{ \App\Filament\Pages\TermOverview::getUrl(['termId' => $availableTerm->id]) }}"><span>{{ $availableTerm->label }}<small>{{ $availableTerm->academicYear?->label }}</small></span><span>Open</span></a>@endforeach</div>

        </x-filament::section>

    @endif

    <x-filament-actions::modals />

</x-filament-panels::page>

