<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @php
            $resolvedStepState = $stepState ?? \App\Support\TermPlanningStepState::evaluate($term, (bool) ($readOnly ?? false));
            $currentStepState = $resolvedStepState['steps']['classes'] ?? ['locked' => false];
            $resourcesUnlocked = (bool) ($resolvedStepState['steps']['resources']['unlocked'] ?? false);
            $isLiveOrClosed = (bool) ($resolvedStepState['is_live_or_closed'] ?? ($this->hasPublishedTimetable() || $this->isTermClosed()));
            $hasOfficialEnrollments = (bool) ($hasOfficialEnrollments ?? $this->hasOfficialStudentEnrollments($term));
            $hideCapacityHeadroom = $this->isTermClosed($term) || ($this->hasPublishedTimetable($term) && $hasOfficialEnrollments);
            $confirmedCohortsCount = $cohorts->whereNotNull('confirmed_at')->count();
            $confirmedClassesCount = (int) ($counts['confirmed'] ?? $contextClasses->whereNotNull('confirmed_at')->count());
        @endphp

        @if (! empty($currentStepState['locked']))
            @include('filament.pages.partials.term-step-locked', ['step' => $currentStepState, 'term' => $term])
        @else
            <section
                class="tala-task-work {{ $classesView === 'cohorts' ? 'tala-term-cohorts' : '' }}"
                aria-label="Sections and classes"
                wire:loading.attr="aria-busy"
                wire:target="chooseClassesView"
            >
                <div class="tala-work-tabs tala-work-tabs--full">
                    <x-tala-navigation label="Sections and classes view" compact stretch class="tala-work-tabs__nav">
                        <x-filament::tabs.item
                            tag="a"
                            :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'cohorts'])"
                            :active="$classesView === 'cohorts'"
                            icon="heroicon-o-user-group"
                            :badge="$cohorts->count()"
                            :badge-color="$classesView === 'cohorts' ? 'primary' : 'gray'"
                            wire:click.prevent="chooseClassesView('cohorts')"
                            wire:loading.attr="aria-disabled"
                            wire:target="chooseClassesView"
                        >
                            Student sections
                        </x-filament::tabs.item>
                        <x-filament::tabs.item
                            tag="a"
                            :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'classes'])"
                            :active="$classesView === 'classes'"
                            icon="heroicon-o-squares-2x2"
                            :badge="$sourceClasses->count()"
                            :badge-color="$classesView === 'classes' ? 'primary' : 'gray'"
                            wire:click.prevent="chooseClassesView('classes')"
                            wire:loading.attr="aria-disabled"
                            wire:target="chooseClassesView"
                        >
                            Classes
                        </x-filament::tabs.item>
                    </x-tala-navigation>
                </div>

                @if ($this->isTermClosed())
                    <x-tala-access-alert variant="neutral" icon="heroicon-o-lock-closed" title="Closed term · Read-only">
                        <p>This term is closed. Student sections and classes are preserved for historical reference and cannot be added or edited.</p>
                    </x-tala-access-alert>
                @elseif ($this->hasPublishedTimetable())
                    @if (! $hasOfficialEnrollments)
                        <x-tala-access-alert variant="info" icon="heroicon-o-information-circle" title="Official timetable is published (0 active enrollments)">
                            <p>
                                Pre-publish student section and class edits are locked while the official timetable is live.
                                Because no students are enrolled yet, you can still generate and publish a new whole-term draft in 4. Timetable, or use <a class="fi-link" href="{{ \App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'timetableState' => 'official']) }}">Timetable &rarr; Revise official schedule</a> for individual class changes.
                            </p>
                        </x-tala-access-alert>
                    @else
                        <x-tala-access-alert variant="info" icon="heroicon-o-lock-closed" title="Official timetable is published">
                            <p>
                                Pre-publish student section and class edits are locked while the official timetable is live.
                                Use <a class="fi-link" href="{{ \App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'timetableState' => 'official']) }}">Timetable &rarr; Revise official schedule</a> for controlled schedule changes.
                            </p>
                        </x-tala-access-alert>
                    @endif
                @elseif ($classesView === 'classes' && ! $activePackage)
                    <div id="term-classes-prereq-notice" class="tala-inline-prereq">
                        <div>
                            <strong>Teaching calendar not active</strong>
                            <p>{{ $readOnly ? 'The Registrar must activate the teaching calendar before loading and confirming classes.' : 'Loading and confirming classes requires an active teaching calendar. Student sections can be added now.' }}</p>
                        </div>
                        <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-calendar" :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Review calendar</x-filament::button>
                    </div>
                @elseif ($classesView === 'classes' && $confirmedCohortsCount === 0)
                    <div id="term-classes-prereq-notice" class="tala-inline-prereq">
                        <div>
                            <strong>Add a student section first</strong>
                            <p>{{ $readOnly ? 'The Registrar must add at least one student section before loading or adding classes.' : 'Add at least one student section first before loading or adding classes.' }}</p>
                        </div>
                        @if (! $readOnly && auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar))
                            <x-filament::button
                                color="primary"
                                size="sm"
                                icon="heroicon-o-plus"
                                wire:click="mountAction('recordCohort')"
                            >
                                Add student section
                            </x-filament::button>
                        @endif
                    </div>
                @endif

                @if (! $hideCapacityHeadroom && ! empty($capacityHeadroom))
                    @php
                        $headroomColor = $capacityHeadroom['status_color'] ?? match ($capacityHeadroom['status']) {
                            'over' => 'danger',
                            'warning' => 'warning',
                            'awaiting' => 'gray',
                            default => 'success',
                        };
                        $fmtMetric = static fn (float|int $value): string => rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
                        $seats = $capacityHeadroom['room_seats'];
                        $hours = $capacityHeadroom['room_hours'];
                        $faculty = $capacityHeadroom['faculty_units'];
                    @endphp
                    @if ($sourceClasses->isEmpty())
                        @php
                            $termTypeLabel = $term->typeLabel();
                            $ayLabel = $term->academicYear?->label ?: $term->label;
                        @endphp
                        <div class="tala-classes-context-bar" role="note" aria-label="Step 2 planning scope">
                            <div class="tala-classes-context-bar__lead">
                                <span class="tala-classes-context-bar__icon" aria-hidden="true">
                                    <x-filament::icon icon="heroicon-o-academic-cap" />
                                </span>
                                <div class="tala-classes-context-bar__copy">
                                    <p class="tala-classes-context-bar__title">
                                        {{ $termTypeLabel }} ({{ $ayLabel }}) schedules all running year levels together
                                    </p>
                                    <p class="tala-classes-context-bar__sub">
                                        Add each student section by year level (Year 1, Year 2, Year 3, …) and auto-load its {{ $termTypeLabel }} subjects.
                                    </p>
                                </div>
                            </div>
                            <div class="tala-classes-context-bar__stats tabular-nums">
                                <span class="tala-classes-context-bar__pill">
                                    <strong>{{ $seats['active_rooms'] }}</strong>
                                    <span>active {{ \Illuminate\Support\Str::plural('room', $seats['active_rooms']) }}@if ($seats['active_rooms'] > 0) · up to {{ $seats['largest_room'] }} seats @endif</span>
                                </span>
                                <span class="tala-classes-context-bar__pill is-muted">
                                    <strong>0</strong>
                                    <span>classes added yet</span>
                                </span>
                            </div>
                        </div>

                        @if (! $readOnly && ! $isLiveOrClosed && ! empty($curriculumReadinessGaps))
                            @php
                                $hasLockedCurriculumGap = collect($curriculumReadinessGaps)->contains(fn (array $gap): bool => (bool) ($gap['is_locked'] ?? false));
                            @endphp
                            <div class="tala-inline-prereq" role="region" aria-label="Curriculum readiness for {{ $termTypeLabel }}">
                                <div class="tala-inline-prereq__body">
                                    <p class="tala-inline-prereq__title">
                                        <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
                                        <span>Curriculum readiness for {{ $termTypeLabel }}</span>
                                    </p>
                                    @foreach ($curriculumReadinessGaps as $gap)
                                        <p class="tala-inline-prereq__text">
                                            <strong>{{ $gap['label'] }}</strong> has 0 subjects for {{ $termTypeLabel }}@if ($gap['configured']) (configured: {{ $gap['configured'] }})@else (no subjects configured yet)@endif
                                            @if (count($curriculumReadinessGaps) > 1)
                                                · <a href="{{ $gap['url'] }}" class="fi-link font-medium">{{ ($gap['is_locked'] ?? false) ? 'Open curriculum (copy to edit)' : 'Open curriculum' }}</a>
                                            @endif
                                        </p>
                                    @endforeach
                                    @if ($hasLockedCurriculumGap)
                                        <p class="tala-inline-prereq__text">
                                            Published curricula are locked; copy the curriculum to add {{ $termTypeLabel }} subjects, or use + Add class.
                                        </p>
                                    @endif
                                </div>
                                <div class="tala-inline-prereq__actions">
                                    @if (count($curriculumReadinessGaps) === 1)
                                        <x-filament::button
                                            tag="a"
                                            color="gray"
                                            size="sm"
                                            icon="heroicon-o-book-open"
                                            :href="$curriculumReadinessGaps[0]['url']"
                                        >
                                            {{ ($curriculumReadinessGaps[0]['is_locked'] ?? false) ? 'Open curriculum (copy to edit)' : 'Open curriculum' }}
                                        </x-filament::button>
                                    @else
                                        <x-filament::button
                                            tag="a"
                                            color="gray"
                                            size="sm"
                                            icon="heroicon-o-book-open"
                                            :href="\App\Filament\Resources\CurriculumVersions\CurriculumVersionResource::getUrl('index')"
                                        >
                                            Open Curricula
                                        </x-filament::button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @else
                        <section class="tala-capacity-headroom is-{{ $capacityHeadroom['status'] }}" aria-labelledby="capacity-headroom-heading">
                            <header class="tala-capacity-headroom__header">
                                <div class="tala-capacity-headroom__title">
                                    <x-filament::icon icon="heroicon-o-chart-bar" class="tala-capacity-headroom__icon" aria-hidden="true" />
                                    <h2 id="capacity-headroom-heading">Capacity headroom</h2>
                                    <x-filament::badge :color="$headroomColor" size="sm">{{ $capacityHeadroom['status_label'] }}</x-filament::badge>
                                </div>
                                <p class="tala-resource-readiness-bar__meta">
                                    All programs for {{ $term->label }} are scheduled together. {{ $classesView === 'cohorts' ? 'Filter by program below.' : 'Filter by program or year below.' }}
                                </p>
                            </header>
                            <dl class="tala-capacity-headroom__grid tabular-nums">
                                <div class="tala-capacity-headroom__stat is-{{ $seats['status'] }}">
                                    <dt>Room seat cap</dt>
                                    <dd>
                                        @if ($seats['active_rooms'] > 0)
                                            <span class="tala-capacity-headroom__value">
                                                <strong>{{ $seats['largest_section'] }} / {{ $seats['largest_room'] }}</strong>
                                                <span class="tala-capacity-headroom__unit">seats</span>
                                            </span>
                                            <span class="tala-capacity-headroom__meta">
                                                @if ($seats['over_capacity_sections'] > 0)
                                                    {{ $seats['over_capacity_sections'] }} {{ \Illuminate\Support\Str::plural('section', $seats['over_capacity_sections']) }} over cap
                                                @else
                                                    {{ $seats['active_rooms'] }} active {{ \Illuminate\Support\Str::plural('room', $seats['active_rooms']) }}
                                                @endif
                                            </span>
                                        @else
                                            <span class="tala-capacity-headroom__value">
                                                <strong>0</strong>
                                                <span class="tala-capacity-headroom__unit">rooms</span>
                                            </span>
                                            <span class="tala-capacity-headroom__meta">No active rooms recorded</span>
                                        @endif
                                    </dd>
                                </div>
                                <div class="tala-capacity-headroom__stat is-{{ $hours['status'] }}">
                                    <dt>Weekly room-hour budget</dt>
                                    <dd>
                                        @if ($hours['available_hours'] > 0)
                                            <span class="tala-capacity-headroom__value">
                                                <strong>{{ $fmtMetric($hours['demanded_hours']) }} / {{ $fmtMetric($hours['available_hours']) }}</strong>
                                                <span class="tala-capacity-headroom__unit">hrs/wk</span>
                                            </span>
                                            <span class="tala-capacity-headroom__meta">{{ $hours['percent'] }}% used</span>
                                        @else
                                            <span class="tala-capacity-headroom__value">
                                                <strong>{{ $fmtMetric($hours['demanded_hours']) }}</strong>
                                                <span class="tala-capacity-headroom__unit">hrs/wk</span>
                                            </span>
                                            <span class="tala-capacity-headroom__meta">{{ $hours['demanded_hours'] > 0 ? 'No room hours available' : '0 room-hrs planned' }}</span>
                                        @endif
                                    </dd>
                                </div>
                                <div class="tala-capacity-headroom__stat is-{{ $faculty['status'] }}">
                                    <dt>Available faculty teaching units</dt>
                                    <dd>
                                        @if ($faculty['available_teachers'] > 0)
                                            <span class="tala-capacity-headroom__value">
                                                <strong>{{ $fmtMetric($faculty['demanded_units']) }} / {{ $fmtMetric($faculty['available_units']) }}</strong>
                                                <span class="tala-capacity-headroom__unit">units</span>
                                            </span>
                                            <span class="tala-capacity-headroom__meta">
                                                {{ $faculty['available_teachers'] }} faculty ready
                                            </span>
                                        @else
                                            <span class="tala-capacity-headroom__value">
                                                <strong>{{ $fmtMetric($faculty['demanded_units']) }}</strong>
                                                <span class="tala-capacity-headroom__unit">units needed</span>
                                            </span>
                                            <span class="tala-capacity-headroom__meta">
                                                Awaiting faculty replies
                                            </span>
                                        @endif
                                    </dd>
                                </div>
                            </dl>
                        </section>
                    @endif
                @elseif (! $hideCapacityHeadroom)
                    <p class="tala-work-footnote">All programs for {{ $term->label }} are scheduled together. {{ $classesView === 'cohorts' ? 'Filter by program below.' : 'Filter by program or year below.' }}</p>
                @endif

                @php
                    $secCounts = $sectionTriageCounts ?? ['all' => 0, 'no_classes' => 0, 'no_subjects' => 0];
                    $clsCounts = $classTriageCounts ?? ['all' => 0, 'needs_preparation' => 0, 'no_teacher' => 0, 'cancelled' => 0];
                    $showSectionTriage = $classesView === 'cohorts'
                        && (($secCounts['no_classes'] ?? 0) > 0 || ($secCounts['no_subjects'] ?? 0) > 0 || $sectionTriage !== 'all');
                    $showClassTriage = $classesView === 'classes'
                        && (($clsCounts['needs_preparation'] ?? 0) > 0 || ($clsCounts['no_teacher'] ?? 0) > 0 || ($clsCounts['cancelled'] ?? 0) > 0 || $classTriage !== 'all');
                @endphp

                @if ($showSectionTriage)
                    <div class="tala-triage-track-wrap">
                        <div class="tala-triage-chips" role="toolbar" aria-label="Quick filters for student sections">
                            <button
                                type="button"
                                wire:click="setSectionTriage('all')"
                                @class(['tala-triage-chip', 'is-active' => $sectionTriage === 'all'])
                                aria-pressed="{{ $sectionTriage === 'all' ? 'true' : 'false' }}"
                            >
                                <span>All</span>
                                <span class="tala-triage-chip__count">{{ (int) ($secCounts['all'] ?? 0) }}</span>
                            </button>
                            @if (($secCounts['no_classes'] ?? 0) > 0 || $sectionTriage === 'no_classes')
                                <button
                                    type="button"
                                    wire:click="setSectionTriage('no_classes')"
                                    @class(['tala-triage-chip', 'is-active' => $sectionTriage === 'no_classes'])
                                    aria-pressed="{{ $sectionTriage === 'no_classes' ? 'true' : 'false' }}"
                                >
                                    <span>No classes linked</span>
                                    <span class="tala-triage-chip__count">{{ (int) ($secCounts['no_classes'] ?? 0) }}</span>
                                </button>
                            @endif
                            @if (($secCounts['no_subjects'] ?? 0) > 0 || $sectionTriage === 'no_subjects')
                                <button
                                    type="button"
                                    wire:click="setSectionTriage('no_subjects')"
                                    @class(['tala-triage-chip', 'tala-triage-chip--warning' => ($secCounts['no_subjects'] ?? 0) > 0, 'is-active' => $sectionTriage === 'no_subjects'])
                                    aria-pressed="{{ $sectionTriage === 'no_subjects' ? 'true' : 'false' }}"
                                >
                                    <span>No curriculum subjects</span>
                                    <span class="tala-triage-chip__count">{{ (int) ($secCounts['no_subjects'] ?? 0) }}</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @elseif ($showClassTriage)
                    <div class="tala-triage-track-wrap">
                        <div class="tala-triage-chips" role="toolbar" aria-label="Quick filters for classes">
                            <button
                                type="button"
                                wire:click="setClassTriage('all')"
                                @class(['tala-triage-chip', 'is-active' => $classTriage === 'all'])
                                aria-pressed="{{ $classTriage === 'all' ? 'true' : 'false' }}"
                            >
                                <span>All active</span>
                                <span class="tala-triage-chip__count">{{ (int) ($clsCounts['all'] ?? 0) }}</span>
                            </button>
                            @if (($clsCounts['needs_preparation'] ?? 0) > 0 || $classTriage === 'needs_preparation')
                                <button
                                    type="button"
                                    wire:click="setClassTriage('needs_preparation')"
                                    @class(['tala-triage-chip', 'tala-triage-chip--warning' => ($clsCounts['needs_preparation'] ?? 0) > 0, 'is-active' => $classTriage === 'needs_preparation'])
                                    aria-pressed="{{ $classTriage === 'needs_preparation' ? 'true' : 'false' }}"
                                >
                                    <span>Needs preparation</span>
                                    <span class="tala-triage-chip__count">{{ (int) ($clsCounts['needs_preparation'] ?? 0) }}</span>
                                </button>
                            @endif
                            @if (($clsCounts['no_teacher'] ?? 0) > 0 || $classTriage === 'no_teacher')
                                <button
                                    type="button"
                                    wire:click="setClassTriage('no_teacher')"
                                    @class(['tala-triage-chip', 'tala-triage-chip--warning' => ($clsCounts['no_teacher'] ?? 0) > 0, 'is-active' => $classTriage === 'no_teacher'])
                                    aria-pressed="{{ $classTriage === 'no_teacher' ? 'true' : 'false' }}"
                                >
                                    <span>No qualified teacher</span>
                                    <span class="tala-triage-chip__count">{{ (int) ($clsCounts['no_teacher'] ?? 0) }}</span>
                                </button>
                            @endif
                            @if (($clsCounts['cancelled'] ?? 0) > 0 || $classTriage === 'cancelled')
                                <button
                                    type="button"
                                    wire:click="setClassTriage('cancelled')"
                                    @class(['tala-triage-chip', 'is-active' => $classTriage === 'cancelled'])
                                    aria-pressed="{{ $classTriage === 'cancelled' ? 'true' : 'false' }}"
                                >
                                    <span>Cancelled</span>
                                    <span class="tala-triage-chip__count">{{ (int) ($clsCounts['cancelled'] ?? 0) }}</span>
                                </button>
                            @endif
                        </div>
                    </div>
                @endif

                {{ $this->table }}

                @php
                    $showSectionsHandoff = $classesView === 'cohorts' && $cohorts->isNotEmpty() && ! $readOnly && ! $isLiveOrClosed;
                    $showResourcesHandoff = $resourcesUnlocked && ! $readOnly && ! $isLiveOrClosed;
                    $sectionsWithoutSubjects = $sourceClasses->isEmpty() && ($allCohortsHaveZeroCurriculumSubjects ?? false);
                @endphp
                @if ($showSectionsHandoff || $showResourcesHandoff)
                    <div class="tala-stage-next">
                        @if ($showSectionsHandoff)
                            @if ($sectionsWithoutSubjects)
                                <p class="tala-stage-next__copy m-0 text-sm tabular-nums">
                                    <strong>{{ $cohorts->count() }} {{ \Illuminate\Support\Str::plural('student section', $cohorts->count()) }}</strong>
                                    recorded. No {{ $term->typeLabel() }} curriculum subjects are configured for these sections yet.
                                </p>
                                <div class="tala-stage-next__actions flex flex-wrap items-center gap-2">
                                    <x-filament::button
                                        tag="a"
                                        color="primary"
                                        size="sm"
                                        icon="heroicon-o-book-open"
                                        :href="\App\Filament\Resources\CurriculumVersions\CurriculumVersionResource::getUrl('index')"
                                    >
                                        Open Curricula
                                    </x-filament::button>
                                    <x-filament::button
                                        tag="a"
                                        color="gray"
                                        size="sm"
                                        icon="heroicon-o-arrow-right"
                                        icon-position="after"
                                        :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'classes'])"
                                        wire:click.prevent="chooseClassesView('classes')"
                                    >
                                        Classes tab
                                    </x-filament::button>
                                </div>
                            @else
                                <p class="tala-stage-next__copy m-0 text-sm tabular-nums">
                                    <strong>{{ $cohorts->count() }} {{ \Illuminate\Support\Str::plural('student section', $cohorts->count()) }}</strong>
                                    @if ($sourceClasses->isNotEmpty())
                                        · {{ $sourceClasses->count() }} {{ \Illuminate\Support\Str::plural('class', $sourceClasses->count()) }} linked. Review classes or continue to faculty and rooms.
                                    @else
                                        recorded. Review and load curriculum classes next.
                                    @endif
                                </p>
                                <div class="tala-stage-next__actions flex flex-wrap items-center gap-2">
                                    <x-filament::button
                                        tag="a"
                                        :color="$showResourcesHandoff ? 'gray' : 'primary'"
                                        size="sm"
                                        icon="heroicon-o-arrow-right"
                                        icon-position="after"
                                        :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'classes'])"
                                        wire:click.prevent="chooseClassesView('classes')"
                                    >
                                        Next: Review classes
                                    </x-filament::button>
                                    @if ($showResourcesHandoff)
                                        <x-filament::button
                                            tag="a"
                                            color="primary"
                                            size="sm"
                                            icon="heroicon-o-arrow-right"
                                            icon-position="after"
                                            :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id])"
                                        >
                                            Next: 3. Faculty &amp; rooms
                                        </x-filament::button>
                                    @endif
                                </div>
                            @endif
                        @else
                            <p class="tala-stage-next__copy m-0 text-sm tabular-nums">
                                <strong>{{ $confirmedClassesCount }} confirmed {{ \Illuminate\Support\Str::plural('class', $confirmedClassesCount) }}</strong>
                                ready for faculty and room setup.
                            </p>
                            <div class="tala-stage-next__actions flex flex-wrap items-center gap-2">
                                <x-filament::button
                                    tag="a"
                                    color="primary"
                                    size="sm"
                                    icon="heroicon-o-arrow-right"
                                    icon-position="after"
                                    :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id])"
                                >
                                    Next: 3. Faculty &amp; rooms
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                @endif
            </section>
        @endif
    @else
        <section class="tala-task-surface" aria-label="Sections and classes">
            <x-servitech-empty-state
                :illustrated="false"
                icon="heroicon-o-rectangle-group"
                heading="Choose a term"
                description="Open Term Planning to select a term and manage its student sections and classes."
            >
                <x-filament::button
                    tag="a"
                    color="primary"
                    size="sm"
                    icon="heroicon-o-arrow-right"
                    icon-position="after"
                    :href="\App\Filament\Pages\TermOverview::getUrl()"
                >
                    Open Term Planning
                </x-filament::button>
            </x-servitech-empty-state>
        </section>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
