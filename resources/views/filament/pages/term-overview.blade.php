<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-guide-summary" aria-label="Term preparation summary">
            @if ($activePackage)<p class="tala-guide-period"><x-filament::icon icon="heroicon-o-calendar-days" />{{ $activePackage->classes_start_on?->format('M j, Y') }} – {{ $activePackage->classes_end_on?->format('M j, Y') }}</p>@endif
            <div class="tala-guide-readiness">
                <div class="tala-guide-readiness-head" aria-hidden="true"><span>Area</span><span>Recorded state</span><span>Details</span><span>Action</span></div>
                <div class="tala-guide-readiness-row">
                    <div class="tala-guide-area"><x-filament::icon icon="heroicon-o-calendar-days" /><strong>Teaching calendar</strong></div>
                    <span @class(['tala-guide-status', 'is-ready' => $activePackage && $draftPackages->isEmpty(), 'needs-work' => $draftPackages->isNotEmpty()])>{{ $draftPackages->isNotEmpty() ? 'Draft to review' : ($activePackage ? 'Ready' : 'Not recorded') }}</span>
                    <p>{{ $overviewPackage ? 'Version '.$overviewPackage->version.' · '.$overviewPackage->state : 'Record class dates and teaching hours.' }}</p>
                    <x-filament::button tag="a" :color="! $readOnly && ! $activePackage ? 'primary' : 'gray'" :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">{{ ! $readOnly && ! $activePackage ? 'Set calendar' : 'Open calendar' }}</x-filament::button>
                </div>
                <div @class(['tala-guide-readiness-row', 'is-next' => $activePackage && ! $this->coverage['summary']['class_count']])>
                    <div class="tala-guide-area"><x-filament::icon icon="heroicon-o-book-open" /><strong>Classes & sections</strong></div>
                    <span class="tala-guide-status">{{ $this->coverage['summary']['class_count'] ? $counts['confirmed'].' of '.$counts['classes'].' confirmed' : 'No classes yet' }}</span>
                    <p>{{ $this->coverage['summary']['class_count'] }} classes · {{ $this->coverage['summary']['section_count'] }} {{ \Illuminate\Support\Str::plural('student section', $this->coverage['summary']['section_count']) }} · {{ $this->coverage['summary']['meeting_count'] }} weekly meetings</p>
                    @if (! $readOnly && $activePackage && ! $this->coverage['summary']['class_count'])<x-filament::button tag="a" :href="\App\Filament\Pages\TermClassProposal::getUrl(['termId' => $term->id])">Prepare Term classes</x-filament::button>
                    @else<x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id])">Open classes</x-filament::button>@endif
                </div>
                <div class="tala-guide-readiness-row">
                    <div class="tala-guide-area"><x-filament::icon icon="heroicon-o-user-group" /><strong>Faculty & rooms</strong></div>
                    <span class="tala-guide-status">{{ $missingQualifications ? 'Teacher needed' : ($facultyDeadline ? 'Review inputs' : 'Deadline not set') }}</span>
                    <p>{{ $facultyDeadline ? 'Availability reply deadline: '.$facultyDeadline->timezone('Asia/Manila')->format('M j, Y') : 'Set a reply deadline, then request Faculty availability.' }}</p>
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id])">Open resources</x-filament::button>
                </div>
                <div class="tala-guide-readiness-row">
                    <div class="tala-guide-area"><x-filament::icon icon="heroicon-o-table-cells" /><strong>Timetable</strong></div>
                    <span @class(['tala-guide-status', 'is-ready' => $currentVersion])>{{ $currentVersion ? 'Official v'.$currentVersion->version : ($activeRun ? 'Draft recorded' : 'No draft') }}</span>
                    <p>{{ $currentVersion ? 'The official version remains in use while another draft is reviewed.' : ($activeRun ? 'Review the draft and its validation before publication.' : 'Prepare the inputs before generating a draft.') }}</p>
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id])">{{ $activeRun && ! $currentVersion ? 'Review draft' : 'Open timetable' }}</x-filament::button>
                </div>
            </div>
        </section>
        @if ($this->coverage['summary']['class_count'] && ! $this->generationReadiness()['ready'])
            @php($recoveryTab = $this->generationReadiness()['tab'] ?? 'overview')
            <div class="tala-task-finding" role="status"><p>{{ $this->generationReadiness()['reason'] }}</p><x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $recoveryTab])">{{ match ($recoveryTab) { 'classes' => 'Review classes', 'resources' => 'Review Faculty and rooms', 'generate' => 'View timetable progress', default => 'Review teaching calendar' } }}</x-filament::button></div>
        @endif
        <section class="tala-guide-coverage">
            <h2>Program & year-level coverage</h2>
            <section class="tala-task-work" aria-label="Program and year-level coverage">
                <p class="tala-work-footnote">Choose the courses that will run and record their student sections and learner counts. Years without classes can stay empty.</p>
                {{ $this->table }}
                <p class="tala-work-footnote">Generation covers the whole recorded ready Term. Shared classes count once; years without classes remain advisory.</p>
            </section>
        </section>
        @if ($this->coverage['additional'])
            <section class="tala-guide-coverage"><h2>Sections needing association · {{ count($this->coverage['additional']) }} to review</h2>
                <ul class="tala-guide-associations">@foreach ($this->coverage['additional'] as $row)<li><strong>{{ $row['program_name'] }}{{ $row['year_level'] ? ' · Year '.$row['year_level'] : '' }}</strong><p>{{ implode(', ', $row['section_references']) ?: 'No section linked' }} · {{ $row['class_count'] }} classes · {{ $row['meeting_count'] }} weekly meetings</p><p>{{ $row['reason'] }}</p></li>@endforeach</ul>
                <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'cohorts'])">Review student sections</x-filament::button>
            </section>
        @endif
    @else
        <div class="tala-term-chooser">
            @if ($terms->isEmpty())
                <x-filament::section heading="No Terms recorded">
                    <p>{{ $readOnly ? 'Ask the Registrar to create a Term before planning begins.' : 'Create a Term to begin planning its calendar, classes, teaching resources and timetable.' }}</p>
                </x-filament::section>
            @else
                @if ($terms->count() > 6)
                    <div class="tala-term-chooser-search">
                        {{ $this->termSelection }}
                        <p>Search by academic year or Term.</p>
                    </div>
                @endif
                @foreach ($terms->groupBy(fn (\App\Models\Term $availableTerm): string => (string) ($availableTerm->academic_year_id ?? 'unrecorded')) as $yearTerms)
                    @php($academicYear = $yearTerms->first()->academicYear)
                    @php($academicYearHeading = $academicYear ? 'Academic year '.$academicYear->label : 'Academic year not recorded')
                    <x-filament::section :heading="$academicYearHeading" :description="$yearTerms->count().' '.\Illuminate\Support\Str::plural('Term', $yearTerms->count())">
                        <ul class="tala-term-chooser-rows">
                            @foreach ($yearTerms as $availableTerm)
                                @php($termState = \App\Models\Term::stateOptions()[$availableTerm->state] ?? $availableTerm->state)
                                @php($termStateColor = match ($availableTerm->state) { \App\Models\Term::StateActive => 'success', default => 'gray' })
                                <li class="tala-term-chooser-row">
                                    <div class="tala-term-chooser-details">
                                        <strong>{{ $availableTerm->label }}</strong>
                                        <p>{{ \App\Models\Term::typeOptions()[$availableTerm->type] ?? $availableTerm->type }} <span aria-hidden="true">·</span> Term dates: <time datetime="{{ $availableTerm->starts_on->toDateString() }}">{{ $availableTerm->starts_on->format('M j, Y') }}</time> – <time datetime="{{ $availableTerm->ends_on->toDateString() }}">{{ $availableTerm->ends_on->format('M j, Y') }}</time></p>
                                    </div>
                                    <x-filament::badge :color="$termStateColor">{{ $termState }}</x-filament::badge>
                                    <x-filament::button tag="a" color="gray" icon="heroicon-o-arrow-right" icon-position="after" :aria-label="'Open Term: '.$availableTerm->label" :href="\App\Filament\Pages\TermOverview::getUrl(['termId' => $availableTerm->id])">Open Term</x-filament::button>
                                </li>
                            @endforeach
                        </ul>
                    </x-filament::section>
                @endforeach
            @endif
        </div>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
