<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @assets
            <link rel="stylesheet" href="{{ asset('css/tala-enrollment.css') }}">
        @endassets
        @php
            $taskRows = [
                ['label' => 'Teaching calendar', 'icon' => 'heroicon-o-calendar-days', 'url' => \App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id]), 'keys' => ['calendar'], 'summary' => $activePackage ? 'Active teaching calendar recorded' : 'Teaching calendar not active'],
                ['label' => 'Classes & student sections', 'icon' => 'heroicon-o-book-open', 'url' => \App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id]), 'keys' => ['classes', 'sections'], 'summary' => $overviewCounts['recorded'].' classes · '.$overviewCounts['confirmed'].' confirmed · '.$overviewCounts['student_sections'].' '.\Illuminate\Support\Str::plural('student section', $overviewCounts['student_sections'])],
                ['label' => 'Faculty & rooms', 'icon' => 'heroicon-o-user-group', 'url' => \App\Filament\Pages\TermResources::getUrl(['termId' => $term->id]), 'keys' => ['resources', 'availability', 'deadline'], 'summary' => 'Teaching availability, qualifications and rooms'],
                ['label' => 'Timetable', 'icon' => 'heroicon-o-table-cells', 'url' => $overviewTimetable['attempt']['url'] ?? $overviewTimetable['official']['url'] ?? \App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id]), 'keys' => ['result', 'generate'], 'summary' => $overviewTimetable['official']['label'] ?? $overviewTimetable['attempt']['label'] ?? 'No timetable yet'],
            ];
        @endphp
        <div class="registrar-workbench tala-planning-workbench">
            <section class="registrar-work-panel tala-planning-task-overview tala-task-surface" aria-labelledby="planning-tasks-heading">
                <h2 id="planning-tasks-heading">Planning overview</h2>
                <p class="tala-work-footnote">{{ $readOnly ? 'Inspect this Term’s preparation and timetable. The Registrar maintains these records.' : 'Prepare the inputs below, then generate, review and publish this Term’s timetable.' }}</p>
                <nav class="registrar-work-list" aria-label="Term planning tasks">
                    @foreach ($taskRows as $task)
                        @php
                            $work = collect($overviewWork)->whereIn('key', $task['keys'])->unique('label');
                            $waiting = collect($overviewWaiting)->whereIn('key', $task['keys'])->unique('label');
                            $isTimetable = $task['keys'] === ['result', 'generate'];
                            $summary = $isTimetable ? ($overviewTimetable['attempt']['label'] ?? $task['summary']).($overviewTimetable['attempt'] && $overviewTimetable['official'] ? ' · Official v'.$currentVersion->version.' stays in use' : '') : $task['summary'];
                        @endphp
                        <a href="{{ $task['url'] }}" class="registrar-work-row">
                            <x-filament::icon :icon="$task['icon']" class="registrar-work-icon" />
                            <span>
                                <strong>{{ $task['label'] }}</strong>
                                <span class="registrar-work-description">{{ $summary }}</span>
                                @foreach ($work as $entry)
                                    @if ($entry['key'] !== 'result')<span class="tala-planning-task-finding">{{ $entry['label'] }}</span>@endif
                                @endforeach
                                @foreach ($waiting as $entry)
                                    @if ($entry['key'] !== 'result')<span class="tala-planning-task-finding">{{ $entry['description'] }}</span>@endif
                                @endforeach
                            </span>
                            @if ($work->isNotEmpty())<x-filament::badge color="primary">{{ $readOnly ? 'Inspect' : 'Review' }}</x-filament::badge>@elseif ($waiting->isNotEmpty())<x-filament::badge color="gray">Waiting</x-filament::badge>@else<span></span>@endif
                            <x-filament::icon icon="heroicon-m-chevron-right" class="registrar-work-arrow" />
                        </a>
                    @endforeach
                </nav>
            </section>
            <details class="tala-task-surface tala-planning-programs tala-planning-program-coverage">
                <summary><span><strong>How Term planning works</strong></span><x-filament::icon icon="heroicon-m-chevron-down" /></summary>
                <div class="tala-planning-coverage-content">
                    <dl class="tala-evidence-checks tala-planning-guide">
                        <div><x-filament::icon icon="heroicon-o-clipboard-document-list" /><div><dt>Prepare the inputs</dt><dd>The Registrar prepares the calendar, classes, teachers and rooms. Faculty supplies availability. These tasks can happen in parallel.</dd></div></div>
                        <div><x-filament::icon icon="heroicon-o-table-cells" /><div><dt>Generate and review</dt><dd>Generate a candidate for the whole Term and review its checks. Correct any named source problem. A repair shows which other meetings would move.</dd></div></div>
                        <div><x-filament::icon icon="heroicon-o-check-badge" /><div><dt>Accept, then publish</dt><dd>Acceptance records the Registrar’s review. Publishing makes the timetable official; any existing official version stays in use until then.</dd></div></div>
                    </dl>
                </div>
            </details>
            <details class="tala-task-surface tala-planning-programs tala-planning-program-coverage">
                <summary><span><strong>Programs and year levels</strong><small>Inspect the recorded coverage for this Term.</small></span><x-filament::icon icon="heroicon-m-chevron-down" /></summary>
                <div class="tala-planning-coverage-content">
                <p class="tala-work-footnote">These are the classes recorded for this Term. A year with no classes is not automatically required to run.</p>
                @php($programGroups = collect([...$this->coverage['rows'], ...$this->coverage['additional']])->unique('id')->groupBy(fn ($row) => $row['program_id'] ?? 'unmapped'))
                @php($programsWithRecords = $programGroups->filter(fn ($rows) => $rows->contains(fn ($row) => $row['class_count'] > 0 || $row['section_count'] > 0)))
                @forelse ($programsWithRecords as $programRows)
                    @php($recordedYears = $programRows->where('class_count', '>', 0)->pluck('year_level')->filter()->unique())
                    <details class="tala-planning-program">
                        <summary>
                            <span><strong>{{ $programRows->first()['program_name'] }}</strong><small>{{ $recordedYears->isNotEmpty() ? $recordedYears->count().' '.\Illuminate\Support\Str::plural('year level', $recordedYears->count()).' with classes' : 'No curriculum year has recorded classes' }}</small>@if ($programRows->contains('status', 'Needs association'))<small class="tala-planning-program-finding">Student-section links need review</small>@endif</span>
                            <x-filament::icon icon="heroicon-m-chevron-down" />
                        </summary>
                        <ul class="tala-planning-program-years">
                            @foreach ($programRows as $row)
                                @php($yearLabel = $row['year_level'] ? (str_contains(strtolower($row['year_level']), 'year') ? $row['year_level'] : 'Year '.$row['year_level']) : 'Student sections to review')
                                <li class="tala-planning-year-row">
                                    <h3>{{ $yearLabel }}</h3>
                                    <div class="tala-planning-year-coverage">
                                        <strong>{{ $row['class_count'] ? $row['class_count'].' '.\Illuminate\Support\Str::plural('class', $row['class_count']) : 'No classes recorded' }}</strong>
                                        <p>{{ $row['meeting_count'] }} {{ \Illuminate\Support\Str::plural('weekly meeting', $row['meeting_count']) }} · {{ $row['section_count'] }} {{ \Illuminate\Support\Str::plural('student section', $row['section_count']) }}</p>
                                        @if ($row['status'] !== 'Prepared' && $row['status'] !== 'Not set up')
                                            <p class="tala-planning-program-finding">{{ $row['status'] === 'Needs association' ? 'Review how these student sections link to classes and curriculum years.' : $row['reason'] }}</p>
                                        @endif
                                    </div>
                                    <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-m-chevron-right" icon-position="after" :aria-label="($row['year_level'] ? 'View '.$yearLabel.' classes for ' : 'Review student sections for ').$programRows->first()['program_name']" :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, ...($row['year_level'] ? ['programId' => $row['program_id'], 'yearLevel' => $row['year_level']] : ['classesView' => 'cohorts'])])">{{ $row['year_level'] ? 'View classes' : 'Review student sections' }}</x-filament::button>
                                </li>
                            @endforeach
                        </ul>
                        <details class="tala-planning-program-source">
                            <summary>Curriculum and student sections</summary>
                            <dl class="tala-planning-reveal tala-planning-program-records">
                                @foreach ($programRows as $row)
                                    <div>
                                        <dt>{{ $row['year_level'] ? (str_contains(strtolower($row['year_level']), 'year') ? $row['year_level'] : 'Year '.$row['year_level']) : 'Student sections to review' }}</dt>
                                        <dd>
                                            @if ($row['curriculum_label'])<p><span>Curriculum</span>{{ $row['curriculum_label'] }}</p>@endif
                                            @if ($row['section_references'])<p><span>Student sections</span>{{ implode(', ', $row['section_references']) }}</p>@endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </details>
                    </details>
                @empty
                    <p>No programs have recorded classes or student sections for this Term yet. {{ $readOnly ? 'The Registrar prepares these records.' : 'Record student sections and prepare the classes that will run.' }}</p>
                @endforelse
                @if ($programGroups->count() > $programsWithRecords->count())
                    <details class="tala-planning-program"><summary><span><strong>Other curriculum programs</strong><small>No classes or student sections recorded for this Term</small></span><x-filament::icon icon="heroicon-m-chevron-down" /></summary>
                        @foreach ($programGroups->diffKeys($programsWithRecords) as $programRows)
                            <div class="tala-planning-program-year"><div><strong>{{ $programRows->first()['program_name'] }}</strong><p>{{ $programRows->pluck('year_level')->filter()->unique()->map(fn ($year) => str_contains(strtolower($year), 'year') ? $year : 'Year '.$year)->implode(' · ') }}</p><small>No classes recorded. The school decides which years will run.</small></div></div>
                        @endforeach
                    </details>
                @endif
                <p class="tala-work-footnote">Shared classes can serve several programs or years; whole-Term totals count each class once.</p>
                </div>
            </details>
            <div class="tala-planning-record-link"><span>Term identity, defaults and history</span><x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\Terms\TermResource::getUrl('view', ['record' => $term, 'viewTab' => 'plan'])">Term settings</x-filament::button></div>
        </div>
    @else
        <div class="tala-term-chooser">
            @if ($terms->isEmpty())
                <section class="tala-task-surface tala-task-work tala-planning-first-use" aria-labelledby="first-term-heading">
                    <x-filament::icon icon="heroicon-o-calendar-days" />
                    <h2 id="first-term-heading">{{ $readOnly ? 'No Terms recorded' : 'Start your first Term' }}</h2>
                    <p>{{ $readOnly ? 'Ask the Registrar to create a Term before planning begins.' : 'An academic year groups your semesters. Add the Term and its dates, then prepare its teaching calendar and classes.' }}</p>
                    @if (! $readOnly)<x-filament::button tag="a" icon="heroicon-o-plus" :href="\App\Filament\Resources\Terms\TermResource::getUrl('create', ['viewTab' => 'plan'])">Add Term</x-filament::button>@endif
                </section>
            @else
                @if ($terms->count() > 6)
                    <div class="tala-term-chooser-search">{{ $this->termSelection }}<p>Search by academic year or Term.</p></div>
                @endif
                <div class="tala-term-selection" aria-label="Recorded Terms">
                    @foreach ($terms->groupBy(fn (\App\Models\Term $availableTerm): string => (string) ($availableTerm->academic_year_id ?? 'unrecorded')) as $yearTerms)
                        @php($academicYear = $yearTerms->first()->academicYear)
                        <section class="tala-term-year-group">
                            <div class="tala-term-year-heading">
                                <x-filament::icon icon="heroicon-o-calendar-days" />
                                <div><h2>{{ $academicYear?->label ?? 'Academic year not recorded' }}</h2><p>Academic year · {{ $yearTerms->count().' '.\Illuminate\Support\Str::plural('Term', $yearTerms->count()) }}</p></div>
                            </div>
                            <ul class="tala-task-surface tala-term-selection-list">
                                @foreach ($yearTerms as $availableTerm)
                                    <li class="tala-term-option">
                                        <div class="tala-term-option-copy">
                                            <div class="tala-term-option-title"><h3>{{ $availableTerm->typeLabel() }}</h3><x-filament::badge :color="$availableTerm->state === \App\Models\Term::StateActive ? 'success' : 'gray'">{{ \App\Models\Term::stateOptions()[$availableTerm->state] ?? $availableTerm->state }}</x-filament::badge></div>
                                            <p class="tala-term-option-dates"><time datetime="{{ $availableTerm->starts_on->toDateString() }}">{{ $availableTerm->starts_on->format('M j, Y') }}</time> – <time datetime="{{ $availableTerm->ends_on->toDateString() }}">{{ $availableTerm->ends_on->format('M j, Y') }}</time></p>
                                            @if ($availableTerm->label !== $availableTerm->typeLabel())<p class="tala-term-option-name">{{ $availableTerm->label }}</p>@endif
                                            <p class="tala-term-option-count">{{ $availableTerm->term_offerings_count ? $availableTerm->term_offerings_count.' course offerings with recorded classes' : 'No classes recorded' }}</p>
                                        </div>
                                        <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-arrow-right" icon-position="after" :aria-label="'Open Term: '.$availableTerm->label" :href="\App\Filament\Pages\TermOverview::getUrl(['termId' => $availableTerm->id])">Open Term</x-filament::button>
                                    </li>
                                @endforeach
                            </ul>
                        </section>
                    @endforeach
                    <p class="tala-work-footnote tala-term-selection-scope"><x-filament::icon icon="heroicon-o-globe-alt" />Each Term covers the school’s programs and year levels.</p>
                </div>
            @endif
        </div>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
