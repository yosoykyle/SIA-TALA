<x-filament-panels::page class="tala-term-workspace">
    @php
        $overviewHeaderActions = $term ? [
            \Filament\Actions\Action::make('editTerm')
                ->label($readOnly ? 'Term settings' : 'Edit Term')
                ->color('gray')
                ->icon($readOnly ? 'heroicon-o-cog-6-tooth' : 'heroicon-o-pencil-square')
                ->url(\App\Filament\Resources\Terms\TermResource::getUrl($readOnly ? 'view' : 'edit', ['record' => $term, 'viewTab' => 'plan'])),
        ] : [];
    @endphp

    @include('filament.pages.partials.term-workspace-header', ['extraHeaderActions' => $overviewHeaderActions])

    @if ($term)
        @php
            $parameters = ['termId' => $term->id];
            $timetable = $overviewTimetable['attempt'] ?? $overviewTimetable['official'];
            $coveragePage = $this->coveragePage;
            $calendarReady = $activePackage !== null && $draftPackages->isEmpty();
            $classesReady = $overviewCounts['student_sections'] > 0
                && $overviewCounts['recorded'] > 0
                && $overviewCounts['confirmed'] >= $overviewCounts['recorded']
                && $overviewCounts['current'] >= $overviewCounts['recorded']
                && $demands->isNotEmpty();
            $resourcesReady = $missingQualifications === 0 && ($readiness['ready'] ?? false);

            $nextStep = match (true) {
                ! $calendarReady => [
                    'step' => 1,
                    'label' => 'Teaching calendar & load',
                    'summary' => $activePackage ? 'Pending calendar update needs activation.' : 'Record and activate teaching dates and weekly hours.',
                    'action' => $readOnly ? 'Inspect calendar' : 'Continue to Step 1',
                    'url' => \App\Filament\Pages\TermCalendar::getUrl($parameters),
                ],
                ! $classesReady => [
                    'step' => 2,
                    'label' => 'Student sections & classes',
                    'summary' => $overviewCounts['recorded'] === 0 ? 'Add student sections and prepare classes for this Term.' : $overviewCounts['confirmed'].' of '.$overviewCounts['recorded'].' classes confirmed.',
                    'action' => $readOnly ? 'Inspect classes' : 'Continue to Step 2',
                    'url' => \App\Filament\Pages\TermClasses::getUrl($parameters),
                ],
                ! $resourcesReady => [
                    'step' => 3,
                    'label' => 'Faculty & rooms',
                    'summary' => 'Confirm faculty qualifications, availability, and active rooms.',
                    'action' => $readOnly ? 'Inspect faculty & rooms' : 'Continue to Step 3',
                    'url' => \App\Filament\Pages\TermResources::getUrl($parameters),
                ],
                $currentVersion === null => [
                    'step' => 4,
                    'label' => $timetable['label'] ?? 'Ready to generate timetable',
                    'summary' => $timetable['description'] ?? 'Generate, review, and publish the Term timetable.',
                    'action' => ($timetable['state'] ?? null) === 'pending' ? 'View progress' : 'Open timetable',
                    'url' => $timetable['url'] ?? \App\Filament\Pages\TermTimetable::getUrl($parameters),
                ],
                default => null,
            };
        @endphp

        <div class="tala-workspace-overview">
            @if ($overviewActionItems !== [])
                <section class="tala-task-surface tala-overview-actions" aria-labelledby="needs-your-action-heading">
                    <header class="tala-overview-actions__header">
                        <div>
                            <h2 id="needs-your-action-heading" class="tala-overview-actions__title">
                                Needs your action
                                <x-filament::badge color="gray" size="sm">{{ count($overviewActionItems) }}</x-filament::badge>
                            </h2>
                            <p>Each item names the affected class or teacher and the direct recovery path.</p>
                        </div>
                    </header>
                    <ul class="tala-overview-actions__list">
                        @foreach ($overviewActionItems as $item)
                            <li class="tala-overview-actions__item">
                                <div class="tala-overview-actions__copy">
                                    <div>
                                        <x-filament::badge :color="$item['severity']" size="sm">{{ $item['badge'] }}</x-filament::badge>
                                    </div>
                                    <h3>{{ $item['title'] }}</h3>
                                    <p>{{ $item['summary'] }}</p>
                                    @if (! empty($item['meetings']) || filled($item['reason']))
                                        <div class="tala-overview-actions__evidence">
                                            @foreach ($item['meetings'] as $meeting)
                                                <div>
                                                    <strong>{{ trim(($meeting['course_code'] ?? '').' · '.($meeting['course_title'] ?? '')) }}</strong>
                                                    @if (! empty($meeting['section_code'])) · {{ $meeting['section_code'] }} @endif
                                                    · {{ $meeting['day_label'] ?? '' }} {{ $meeting['time_label'] ?? '' }}
                                                </div>
                                            @endforeach
                                            @if (filled($item['reason']))
                                                <div class="tala-overview-actions__reason">Reason: “{{ $item['reason'] }}”</div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <div class="tala-overview-actions__cta">
                                    @if (! empty($item['secondary_action']))
                                        <x-filament::button tag="a" color="gray" size="sm" :href="$item['secondary_action']['url']">{{ $item['secondary_action']['label'] }}</x-filament::button>
                                    @endif
                                    <x-filament::button tag="a" :color="$item['primary_action']['color']" size="sm" icon="heroicon-m-arrow-right" icon-position="after" :href="$item['primary_action']['url']">{{ $item['primary_action']['label'] }}</x-filament::button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @elseif ($overviewReassurance !== null)
                <section class="tala-task-surface tala-overview-ready" role="status" aria-labelledby="no-blockers-heading">
                    <div class="tala-overview-ready__lead">
                        <x-filament::icon icon="heroicon-o-check-circle" class="tala-overview-ready__icon" />
                        <div class="tala-overview-ready__copy">
                            <h2 id="no-blockers-heading">{{ $overviewReassurance['heading'] }}</h2>
                            <p>{{ $overviewReassurance['body'] }}</p>
                        </div>
                    </div>
                    <div class="tala-overview-ready__actions">
                        <x-filament::button tag="a" color="gray" size="sm" :href="$overviewReassurance['action_url']">{{ $overviewReassurance['action_label'] }}</x-filament::button>
                        <x-filament::button tag="a" color="primary" size="sm" icon="heroicon-m-arrow-right" icon-position="after" :href="$nextStep['url'] ?? ($timetable['url'] ?? \App\Filament\Pages\TermTimetable::getUrl($parameters))">{{ $nextStep['action'] ?? 'Open timetable' }}</x-filament::button>
                    </div>
                </section>
            @else
                <section class="tala-task-surface tala-overview-ready tala-overview-ready--compact" role="status">
                    <div class="tala-overview-ready__lead">
                        <x-filament::icon :icon="$nextStep === null ? 'heroicon-o-check-circle' : 'heroicon-o-arrow-right-circle'" class="tala-overview-ready__icon" />
                        <p class="tala-overview-ready__line">
                            @if ($nextStep !== null)
                                <strong>Step {{ $nextStep['step'] }} · {{ $nextStep['label'] }}</strong>
                                <span aria-hidden="true">·</span>
                                <span>{{ $nextStep['summary'] }}</span>
                                @if ($overviewTimetable['official'] && $overviewTimetable['attempt'])
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $overviewTimetable['attempt']['label'] }} · {{ $overviewTimetable['official']['label'] }} stays in use</span>
                                @endif
                            @else
                                <strong>{{ $overviewTimetable['official']['label'] ?? 'Official timetable published' }}</strong>
                                @if ($overviewTimetable['attempt'])
                                    <span aria-hidden="true">·</span>
                                    <span>{{ $overviewTimetable['attempt']['label'] }} (official timetable stays in use)</span>
                                @endif
                            @endif
                        </p>
                    </div>
                    <div class="tala-overview-ready__actions">
                        @if ($overviewTimetable['official'] && $overviewTimetable['attempt'])
                            <x-filament::button tag="a" color="gray" size="sm" :href="$overviewTimetable['official']['url']">View official timetable</x-filament::button>
                        @endif
                        <x-filament::button tag="a" :color="$nextStep !== null && ! $readOnly ? 'primary' : 'gray'" size="sm" icon="heroicon-m-arrow-right" icon-position="after" :href="$nextStep['url'] ?? ($timetable['url'] ?? \App\Filament\Pages\TermTimetable::getUrl($parameters))">{{ $nextStep['action'] ?? 'Open timetable' }}</x-filament::button>
                    </div>
                </section>
            @endif

            <section id="program-coverage" class="tala-task-surface tala-workspace-coverage" aria-labelledby="program-coverage-heading">
                <header class="tala-workspace-coverage__toolbar">
                    <div class="tala-workspace-coverage__heading">
                        <h2 id="program-coverage-heading">Programs and year levels</h2>
                        <p>A year without classes is not automatically required to run.</p>
                    </div>
                    <div class="tala-workspace-coverage__controls">
                        <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="tala-workspace-coverage__search">
                            <x-filament::input
                                id="coverage-search"
                                type="search"
                                wire:model.live.debounce.350ms="coverageSearch"
                                placeholder="Search program or curriculum"
                                aria-label="Find a program or curriculum"
                            />
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper class="tala-workspace-coverage__year">
                            <x-filament::input.select
                                id="coverage-year"
                                wire:model.live="coverageYear"
                                aria-label="Year level"
                            >
                                <option value="">All year levels</option>
                                @foreach ($this->coverageYears as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        @if ($coverageSearch !== '' || $coverageYear !== '')
                            <x-filament::button color="gray" size="sm" wire:click="clearCoverageFilters">Clear</x-filament::button>
                        @endif
                    </div>
                </header>

                @if ($coveragePage->isNotEmpty())
                    <div class="tala-data-table-scroll tala-workspace-coverage__table" role="region" aria-label="Program, curriculum and year-level coverage for {{ $term->typeLabel() }}" tabindex="0" wire:loading.class="opacity-60" wire:target="coverageSearch,coverageYear,clearCoverageFilters,gotoPage,nextPage,previousPage">
                        <table class="tala-data-table">
                            <caption class="sr-only">Program, curriculum and year-level coverage for {{ $term->typeLabel() }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Program</th>
                                    <th scope="col">Year level</th>
                                    <th scope="col" class="tala-workspace-coverage__num">Classes</th>
                                    <th scope="col" class="tala-workspace-coverage__num">Confirmed</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="tala-workspace-coverage__action-col"><span class="sr-only">Action</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($coveragePage as $row)
                                    @php
                                        $yearLabel = $row['year_level'] ? (str_contains(strtolower($row['year_level']), 'year') ? $row['year_level'] : 'Year '.$row['year_level']) : 'Year not associated';
                                        $curriculumBadgeColor = match ($row['curriculum_state_label'] ?? null) {
                                            'Active' => 'success',
                                            'Draft' => 'info',
                                            default => 'gray',
                                        };
                                        $confirmedCount = collect($row['classes'] ?? [])->where('confirmed', true)->count();
                                        $statusLabel = $row['status'] ?? 'Not set up';
                                        $statusColor = match ($statusLabel) {
                                            'Prepared' => 'success',
                                            'Needs preparation' => 'warning',
                                            'Needs association', 'Association unresolved' => 'danger',
                                            default => 'gray',
                                        };
                                    @endphp
                                    <tr wire:key="coverage-{{ $row['id'] }}">
                                        <th scope="row">
                                            <strong>{{ $row['program_name'] }}</strong>
                                            <small class="tala-workspace-coverage__curriculum">
                                                <span>{{ $row['curriculum_label'] }}</span>
                                                @if (! empty($row['curriculum_state_label']))
                                                    <x-filament::badge :color="$curriculumBadgeColor" size="sm">{{ $row['curriculum_state_label'] }}</x-filament::badge>
                                                @endif
                                            </small>
                                            @if (in_array($statusLabel, ['Needs association', 'Association unresolved'], true))
                                                <small>Student-section links need review</small>
                                            @endif
                                        </th>
                                        <td data-label="Year level" class="whitespace-nowrap">{{ $yearLabel }}</td>
                                        <td data-label="Classes" class="tala-workspace-coverage__num">{{ $row['class_count'] }}</td>
                                        <td data-label="Confirmed" class="tala-workspace-coverage__num">{{ $confirmedCount }}</td>
                                        <td data-label="Status">
                                            <x-filament::badge :color="$statusColor" size="sm">{{ $statusLabel }}</x-filament::badge>
                                        </td>
                                        <td class="tala-workspace-coverage__action">
                                            <x-filament::button
                                                tag="a"
                                                color="gray"
                                                size="sm"
                                                icon="heroicon-m-arrow-right"
                                                icon-position="after"
                                                :aria-label="($row['year_level'] ? 'Review '.$yearLabel.' classes for ' : 'Review student sections for ').$row['program_name'].' · '.$row['curriculum_label']"
                                                :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, ...($row['year_level'] ? ['programId' => $row['program_id'], 'yearLevel' => $row['year_level']] : ['classesView' => 'cohorts'])])"
                                            >
                                                {{ $row['year_level'] ? 'Review classes' : 'Review student sections' }}
                                            </x-filament::button>
                                        </td>
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
                        <x-servitech-empty-state :illustrated="$this->coverageEntries->isEmpty()" :heading="$this->coverageEntries->isEmpty() ? 'No curriculum coverage recorded' : 'No matching coverage records'" :description="$this->coverageEntries->isEmpty() ? 'Prepare the classes and student sections that will run in this term.' : 'Try another program, curriculum or year level, or clear the filters.'" heading-tag="h3">
                            @if ($this->coverageEntries->isEmpty())
                                <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermClasses::getUrl($parameters)">View classes</x-filament::button>
                            @else
                                <x-filament::button color="gray" wire:click="clearCoverageFilters">Clear filters</x-filament::button>
                            @endif
                        </x-servitech-empty-state>
                    </div>
                @endif
                <p class="tala-workspace-coverage__note">Shared classes may serve several programs or years. Whole-term figures count each class once and are unchanged by these filters.</p>
            </section>
        </div>
    @else
        <div class="tala-term-chooser">
            @if ($terms->isEmpty())
                <section class="tala-task-surface tala-task-work tala-planning-first-use servitech-journey-scene" aria-labelledby="first-term-heading">
                    <x-servitech-empty-state illustrated :heading="$readOnly ? 'No Terms recorded' : 'Start your first Term'" :description="$readOnly ? 'Ask the Registrar to create a Term before planning begins.' : 'An academic year groups your semesters. Add the Term and its dates, then prepare its teaching calendar and classes.'" id="first-term-heading">
                        @if (! $readOnly)
                            <x-filament::button tag="a" icon="heroicon-o-plus" :href="\App\Filament\Resources\Terms\TermResource::getUrl('create', ['viewTab' => 'plan'])">Add Term</x-filament::button>
                        @endif
                    </x-servitech-empty-state>
                </section>
            @else
                <section class="tala-task-surface tala-term-chooser-surface" aria-label="Recorded Terms">
                    <div class="tala-term-chooser__toolbar">
                        <x-tala-navigation compact label="Filter Terms by status" class="tala-term-chooser__tabs">
                            <x-filament::tabs.item
                                :active="$activeTermStateFilter === 'all'"
                                :badge="$termStateCounts['all'] ?? 0"
                                wire:click="setTermStateFilter('all')"
                            >
                                All
                            </x-filament::tabs.item>
                            <x-filament::tabs.item
                                :active="$activeTermStateFilter === \App\Models\Term::StateActive"
                                :badge="$termStateCounts[\App\Models\Term::StateActive] ?? 0"
                                wire:click="setTermStateFilter('{{ \App\Models\Term::StateActive }}')"
                            >
                                Active
                            </x-filament::tabs.item>
                            <x-filament::tabs.item
                                :active="$activeTermStateFilter === \App\Models\Term::StateDraft"
                                :badge="$termStateCounts[\App\Models\Term::StateDraft] ?? 0"
                                wire:click="setTermStateFilter('{{ \App\Models\Term::StateDraft }}')"
                            >
                                Draft
                            </x-filament::tabs.item>
                            <x-filament::tabs.item
                                :active="$activeTermStateFilter === \App\Models\Term::StateClosed"
                                :badge="$termStateCounts[\App\Models\Term::StateClosed] ?? 0"
                                wire:click="setTermStateFilter('{{ \App\Models\Term::StateClosed }}')"
                            >
                                Closed
                            </x-filament::tabs.item>
                        </x-tala-navigation>

                        <div class="tala-term-chooser__controls">
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass" class="tala-term-chooser__search">
                                <x-filament::input
                                    id="term-chooser-search"
                                    type="search"
                                    wire:model.live.debounce.250ms="termSearch"
                                    placeholder="Search Terms"
                                    aria-label="Search Terms"
                                />
                            </x-filament::input.wrapper>

                            @if (count($termAcademicYearOptions) > 0)
                                <x-filament::input.wrapper class="tala-term-chooser__year">
                                    <x-filament::input.select
                                        id="term-chooser-year"
                                        wire:model.live="termAcademicYear"
                                        aria-label="Academic year"
                                    >
                                        <option value="">All academic years</option>
                                        @foreach ($termAcademicYearOptions as $yearId => $yearLabel)
                                            <option value="{{ $yearId }}">{{ $yearLabel }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            @endif

                            @if ($termSearch !== '' || $termAcademicYear !== '' || $activeTermStateFilter !== 'all')
                                <x-filament::button color="gray" size="sm" wire:click="clearTermFilters">Clear</x-filament::button>
                            @endif
                        </div>
                    </div>

                    @if ($chooserPage->isNotEmpty())
                        <div class="tala-data-table-scroll tala-term-chooser__table-wrap" role="region" aria-label="Recorded Terms" tabindex="0" wire:loading.class="opacity-60" wire:target="termSearch,termStateFilter,termAcademicYear,clearTermFilters,gotoPage,nextPage,previousPage">
                            <table class="tala-data-table tala-term-chooser-table">
                                <caption class="sr-only">Recorded Terms</caption>
                                <thead>
                                    <tr>
                                        <th scope="col">Term</th>
                                        <th scope="col">Academic year</th>
                                        <th scope="col">Dates</th>
                                        <th scope="col">Status</th>
                                        <th scope="col">Scope</th>
                                        <th scope="col" class="tala-term-chooser-table__action-col"><span class="sr-only">Action</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($chooserPage as $availableTerm)
                                        @php
                                            $stateColor = match ($availableTerm->state) {
                                                \App\Models\Term::StateActive => 'success',
                                                \App\Models\Term::StateDraft => 'info',
                                                default => 'gray',
                                            };
                                            $stateLabel = \App\Models\Term::stateOptions()[$availableTerm->state] ?? $availableTerm->state;
                                            $startLabel = $availableTerm->starts_on->year === $availableTerm->ends_on->year
                                                ? $availableTerm->starts_on->format('M j')
                                                : $availableTerm->starts_on->format('M j, Y');
                                            $offeringsCount = (int) ($availableTerm->term_offerings_count ?? 0);
                                            $classesCount = (int) ($termClassCounts[$availableTerm->id] ?? 0);
                                        @endphp
                                        <tr wire:key="term-row-{{ $availableTerm->id }}">
                                            <th scope="row">
                                                <strong>{{ $availableTerm->typeLabel() }}</strong>
                                                @if ($availableTerm->label !== $availableTerm->typeLabel())
                                                    <small>{{ $availableTerm->label }}</small>
                                                @endif
                                            </th>
                                            <td data-label="Academic year" class="whitespace-nowrap">
                                                {{ $availableTerm->academicYear?->label ?? '—' }}
                                            </td>
                                            <td data-label="Dates" class="tala-term-chooser-table__dates">
                                                <time datetime="{{ $availableTerm->starts_on->toDateString() }}">{{ $startLabel }}</time>
                                                <span aria-hidden="true">–</span>
                                                <time datetime="{{ $availableTerm->ends_on->toDateString() }}">{{ $availableTerm->ends_on->format('M j, Y') }}</time>
                                            </td>
                                            <td data-label="Status">
                                                <x-filament::badge :color="$stateColor" size="sm">{{ $stateLabel }}</x-filament::badge>
                                            </td>
                                            <td data-label="Scope" class="tala-term-chooser-table__scope">
                                                @if ($offeringsCount > 0 || $classesCount > 0)
                                                    {{ $offeringsCount }} {{ \Illuminate\Support\Str::plural('offering', $offeringsCount) }}
                                                    @if ($classesCount > 0)
                                                        · {{ $classesCount }} {{ \Illuminate\Support\Str::plural('class', $classesCount) }}
                                                    @endif
                                                @else
                                                    <span class="tala-term-chooser-table__muted">No classes yet</span>
                                                @endif
                                            </td>
                                            <td class="tala-term-chooser-table__action">
                                                <x-filament::button
                                                    tag="a"
                                                    color="gray"
                                                    size="sm"
                                                    icon="heroicon-m-arrow-right"
                                                    icon-position="after"
                                                    :aria-label="'Open Term: '.$availableTerm->label"
                                                    :href="\App\Filament\Pages\TermOverview::getUrl(['termId' => $availableTerm->id])"
                                                >
                                                    Open Term
                                                </x-filament::button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @if ($chooserPage->hasPages() || $chooserPage->total() > 0)
                            <div class="tala-term-chooser__pagination">
                                <p role="status">{{ $chooserPage->firstItem() }}–{{ $chooserPage->lastItem() }} of {{ $chooserPage->total() }} {{ \Illuminate\Support\Str::plural('Term', $chooserPage->total()) }}</p>
                                {{ $chooserPage->links() }}
                            </div>
                        @endif
                    @else
                        <div class="tala-term-chooser__empty">
                            <x-servitech-empty-state heading="No matching Terms" description="Try another status tab, academic year, or search term." heading-tag="h3">
                                <x-filament::button color="gray" wire:click="clearTermFilters">Clear filters</x-filament::button>
                            </x-servitech-empty-state>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
