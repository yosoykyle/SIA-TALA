<x-filament-panels::page class="tala-term-workspace">
    @if (! $term)@include('filament.pages.partials.term-workspace-header')@endif
    @if ($term)
        @php
            $parameters = ['termId' => $term->id];
            $tasks = [
                ['label' => 'Classes & student sections', 'icon' => 'heroicon-o-book-open', 'url' => \App\Filament\Pages\TermClasses::getUrl($parameters), 'keys' => ['classes', 'sections']],
                ['label' => 'Faculty & rooms', 'icon' => 'heroicon-o-user-group', 'url' => \App\Filament\Pages\TermResources::getUrl($parameters), 'keys' => ['resources', 'availability', 'deadline']],
                ['label' => 'Teaching calendar', 'icon' => 'heroicon-o-calendar-days', 'url' => \App\Filament\Pages\TermCalendar::getUrl($parameters), 'keys' => ['calendar']],
            ];
            $timetable = $overviewTimetable['attempt'] ?? $overviewTimetable['official'];
            $coveragePage = $this->coveragePage;
        @endphp
        <div class="tala-workspace-overview">
            <header class="tala-workspace-overview__heading">
                <x-filament-panels::header :heading="$term->typeLabel()" :subheading="$term->academicYear?->label"
                    :breadcrumbs="$readOnly ? [\App\Filament\Pages\AcademicApprovals::getUrl(['termId' => $term->id]) => 'Academic oversight', \App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true]) => 'Term planning', 'Term overview'] : [\App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true]) => 'Term planning', 'Term overview']"
                    :actions="[\Filament\Actions\Action::make('changeTerm')->label('Change Term')->color('gray')->icon('heroicon-o-arrows-right-left')->url(\App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true]))]" />
                <div class="tala-workspace-overview__context">
                    <span><x-filament::icon icon="heroicon-o-calendar-days" /><time datetime="{{ $term->starts_on->toDateString() }}">{{ $term->starts_on->format('M j') }}</time> – <time datetime="{{ $term->ends_on->toDateString() }}">{{ $term->ends_on->format('M j, Y') }}</time></span>
                    <span>Record status: <x-filament::badge color="gray">{{ \App\Models\Term::stateOptions()[$term->state] ?? $term->state }}</x-filament::badge></span>
                    <span><x-filament::icon icon="heroicon-o-globe-alt" />School-wide · All programs and year levels</span>
                </div>
                @if ($this->termAutoSelected)<p class="tala-work-footnote">Opened automatically: the only Term with an active teaching calendar.</p>@endif
            </header>

            <div class="tala-workspace-overview__main">
                <section class="tala-task-surface tala-workspace-preparation" aria-labelledby="planning-tasks-heading">
                    <header>
                        <h2 id="planning-tasks-heading">{{ $readOnly ? 'Term preparation' : 'Prepare this term' }}</h2>
                        <p>{{ $readOnly ? 'Read-only · The Registrar maintains these records.' : 'Calendar, class and faculty preparation can happen in parallel.' }}</p>
                    </header>
                    <nav aria-label="Term planning tasks">
                        @foreach ($tasks as $task)
                            @php
                                $work = collect($overviewWork)->whereIn('key', $task['keys'])->unique('label');
                                $waiting = collect($overviewWaiting)->whereIn('key', $task['keys'])->unique('label');
                            @endphp
                            <section class="tala-workspace-preparation__row">
                                <x-filament::icon :icon="$task['icon']" />
                                <div class="tala-workspace-preparation__content">
                                    <h3>{{ $task['label'] }}</h3>
                                    @if ($task['keys'] === ['classes', 'sections'])
                                        <div class="tala-workspace-preparation__figure"><strong>{{ $overviewCounts['recorded'] }}</strong><span>{{ \Illuminate\Support\Str::plural('recorded class', $overviewCounts['recorded']) }}</span></div>
                                        <p>{{ $overviewCounts['confirmed'] }} confirmed · {{ $overviewCounts['student_sections'] }} {{ \Illuminate\Support\Str::plural('student section', $overviewCounts['student_sections']) }}</p>
                                    @elseif ($task['keys'] === ['resources', 'availability', 'deadline'])
                                        @if ($missingQualifications > 0)
                                            <div class="tala-workspace-preparation__figure"><strong>{{ $missingQualifications }}</strong><span>{{ \Illuminate\Support\Str::plural('course', $missingQualifications) }} without eligible faculty</span></div>
                                        @else
                                            <p>Teaching availability, qualifications and rooms</p>
                                        @endif
                                    @else
                                        <p class="tala-workspace-preparation__status">{{ $activePackage ? 'Active calendar · v'.$activePackage->version : 'No active teaching calendar' }}</p>
                                    @endif
                                    @if ($work->isNotEmpty() || $waiting->isNotEmpty())
                                        <ul class="tala-workspace-preparation__findings">
                                            @foreach ($work as $entry)
                                                <li>@if ($entry['key'] === 'sections')<a class="tala-workspace-overview__link" {{ \Filament\Support\generate_href_html($entry['url']) }}>{{ $entry['label'] }}</a>@else{{ $entry['label'] }}@endif</li>
                                            @endforeach
                                            @foreach ($waiting as $entry)<li>{{ $entry['description'] }}</li>@endforeach
                                        </ul>
                                    @endif
                                    <div class="tala-workspace-preparation__action">
                                        <x-filament::button tag="a" :href="$task['url']" :color="$loop->first && ! $readOnly ? 'primary' : 'gray'" icon="heroicon-m-arrow-right" icon-position="after">{{ $readOnly ? 'Inspect' : ($loop->first ? 'Review classes' : ($loop->index === 1 ? 'Review faculty' : 'Open calendar')) }}</x-filament::button>
                                    </div>
                                </div>
                            </section>
                        @endforeach
                    </nav>
                </section>

                <aside class="tala-workspace-overview__aside" aria-label="Timetable and term record">
                    <section class="tala-task-surface tala-workspace-overview__timetable" aria-labelledby="overview-timetable-heading">
                        <h2 id="overview-timetable-heading">Timetable</h2>
                        <div class="tala-workspace-overview__result">
                            <x-filament::icon icon="heroicon-o-table-cells" />
                            <h3>{{ $timetable['label'] ?? 'No timetable yet' }}</h3>
                            <p>{{ $timetable['description'] ?? 'Generate and review a candidate in the timetable workspace. Acceptance records your review; publishing makes it official.' }}</p>
                            @if ($overviewTimetable['official'] && $overviewTimetable['attempt'])
                                <p>{{ $overviewTimetable['official']['label'] }} stays in use.</p>
                            @endif
                            <x-filament::button tag="a" color="gray" icon="heroicon-m-arrow-right" icon-position="after" :href="$timetable['url'] ?? \App\Filament\Pages\TermTimetable::getUrl($parameters)">{{ ($timetable['state'] ?? null) === 'pending' ? 'View progress' : 'Open timetable' }}</x-filament::button>
                            @if ($overviewTimetable['official'] && $overviewTimetable['attempt'])
                                <a class="tala-workspace-overview__link" {{ \Filament\Support\generate_href_html($overviewTimetable['official']['url']) }}>View official timetable</a>
                            @endif
                        </div>
                        <p class="tala-workspace-overview__guide">Prepare inputs → Generate and review → Accept, then publish.</p>
                    </section>
                    <section class="tala-task-surface tala-workspace-overview__record" aria-labelledby="overview-term-record-heading">
                        <header><h2 id="overview-term-record-heading">Term details</h2><x-filament::button tag="a" color="gray" size="sm" :href="\App\Filament\Resources\Terms\TermResource::getUrl('view', ['record' => $term, 'viewTab' => 'plan'])">Term settings</x-filament::button></header>
                        <dl>
                            @if ($term->label !== $term->typeLabel())<div><dt>Recorded name</dt><dd>{{ $term->label }}</dd></div>@endif
                            <div><dt>Term dates</dt><dd>{{ $term->starts_on->format('M j') }} – {{ $term->ends_on->format('M j, Y') }}</dd></div>
                            <div><dt>Record status</dt><dd>{{ \App\Models\Term::stateOptions()[$term->state] ?? $term->state }}</dd></div>
                            <div><dt>Scope</dt><dd>School-wide · All programs and year levels</dd></div>
                        </dl>
                    </section>
                </aside>
            </div>

            <section id="program-coverage" class="tala-task-surface tala-workspace-coverage" aria-labelledby="program-coverage-heading">
                <header><h2 id="program-coverage-heading">Programs and year levels</h2><p>Curriculum coverage for this term. A year without classes is not automatically required to run.</p></header>
                <div class="tala-workspace-coverage__filters">
                    <div><label for="coverage-search">Find a program or curriculum</label><x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input id="coverage-search" type="search" wire:model.live.debounce.350ms="coverageSearch" placeholder="Program or curriculum" /></x-filament::input.wrapper></div>
                    <div><label for="coverage-year">Year level</label><x-filament::input.wrapper><x-filament::input.select id="coverage-year" wire:model.live="coverageYear"><option value="">All year levels</option>@foreach ($this->coverageYears as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></div>
                    @if ($coveragePage->isNotEmpty() && ($coverageSearch !== '' || $coverageYear !== ''))<x-filament::button color="gray" wire:click="clearCoverageFilters">Clear filters</x-filament::button>@endif
                </div>
                @if ($coveragePage->isNotEmpty())
                    <div class="tala-workspace-coverage__table" wire:loading.class="opacity-60" wire:target="coverageSearch,coverageYear,gotoPage,nextPage,previousPage">
                        <table>
                            <caption class="sr-only">Program, curriculum and year-level coverage for {{ $term->typeLabel() }}</caption>
                            <thead><tr><th scope="col">Program / curriculum</th><th scope="col">Year level</th><th scope="col">Classes</th><th scope="col">Weekly meetings</th><th scope="col">Student sections</th><th scope="col"><span class="sr-only">Open records</span></th></tr></thead>
                            <tbody>
                                @foreach ($coveragePage as $row)
                                    @php($yearLabel = $row['year_level'] ? (str_contains(strtolower($row['year_level']), 'year') ? $row['year_level'] : 'Year '.$row['year_level']) : 'Year not associated')
                                    <tr wire:key="coverage-{{ $row['id'] }}">
                                        <th scope="row"><strong>{{ $row['program_name'] }}</strong><small>{{ $row['curriculum_label'] }}</small>@if ($row['status'] === 'Needs association' || $row['status'] === 'Association unresolved')<small>Student-section links need review</small>@endif</th>
                                        <td data-label="Year level">{{ $yearLabel }}</td>
                                        <td data-label="Classes">{{ $row['class_count'] }}</td>
                                        <td data-label="Weekly meetings">{{ $row['meeting_count'] }}</td>
                                        <td data-label="Student sections">{{ $row['section_count'] }}</td>
                                        <td class="tala-workspace-coverage__action"><x-filament::button tag="a" color="gray" size="sm" icon="heroicon-m-arrow-right" icon-position="after" :aria-label="($row['year_level'] ? 'View '.$yearLabel.' classes for ' : 'Review student sections for ').$row['program_name'].' · '.$row['curriculum_label']" :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, ...($row['year_level'] ? ['programId' => $row['program_id'], 'yearLevel' => $row['year_level']] : ['classesView' => 'cohorts'])])">{{ $row['year_level'] ? 'View classes' : 'Review student sections' }}</x-filament::button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="tala-workspace-coverage__pagination">
                        <p role="status">{{ $coveragePage->firstItem() }}–{{ $coveragePage->lastItem() }} of {{ $coveragePage->total() }} coverage records</p>
                        {{ $coveragePage->links(data: ['scrollTo' => '#program-coverage']) }}
                    </div>
                @else
                    <div class="tala-workspace-coverage__empty">
                        <h3>{{ $this->coverageEntries->isEmpty() ? 'No curriculum coverage recorded' : 'No matching coverage records' }}</h3>
                        <p>{{ $this->coverageEntries->isEmpty() ? 'Prepare the classes and student sections that will run in this term.' : 'Try another program, curriculum or year level, or clear the filters.' }}</p>
                        @if ($this->coverageEntries->isEmpty())<x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermClasses::getUrl($parameters)">View classes</x-filament::button>@else<x-filament::button color="gray" wire:click="clearCoverageFilters">Clear filters</x-filament::button>@endif
                    </div>
                @endif
                <p class="tala-workspace-coverage__note">Shared classes may serve several programs or years. Whole-term figures count each class once and are unchanged by these filters.</p>
            </section>
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
