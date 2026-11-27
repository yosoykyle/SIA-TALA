<x-filament-panels::page class="tala-term-workspace tala-faculty-calendar-page">
    <div class="tala-faculty-workflow">
        @if (! $term)
            <x-servitech-empty-state
                illustrated
                icon="heroicon-o-calendar-days"
                heading="No active term yet"
                description="The active term calendar appears here once the Registrar activates a term."
            />
        @else
            <x-record-scope-bar class="tala-faculty-term-card"
                label="Active term"
                icon="heroicon-o-calendar-days"
                :scope-title="$scopeTitle"
                :scope-meta="$scopeMeta"
            />

            @if (! $package)
                <section class="tala-task-surface" aria-label="Academic calendar status">
                    <x-servitech-empty-state
                        :illustrated="false"
                        icon="heroicon-o-calendar"
                        heading="Term calendar is not active yet"
                        description="Teaching dates, approved weekly hours, academic windows, and no-class dates appear here once the Registrar activates the term calendar."
                    />
                </section>
            @else
                <div class="tala-calendar-grid tala-faculty-calendar-grid">
                    <section class="tala-task-surface tala-calendar-sheet" aria-labelledby="faculty-teaching-period-heading">
                        <header class="tala-calendar-sheet-heading">
                            <div class="tala-calendar-sheet-heading__title">
                                <h2 id="faculty-teaching-period-heading">
                                    <x-filament::icon icon="heroicon-o-calendar" aria-hidden="true" />
                                    <span>Teaching period &amp; weekly hours</span>
                                </h2>
                                <x-filament::badge :color="$hasActiveCoreCalendar ? 'success' : 'gray'" size="sm">
                                    {{ $hasActiveCoreCalendar ? 'Active' : 'Closed' }}
                                </x-filament::badge>
                            </div>
                            @if ($teachingPeriod['updated_at_label'])
                                <span class="tala-calendar-sheet-heading__meta tabular-nums">
                                    Updated <time datetime="{{ $teachingPeriod['updated_at_iso'] }}">{{ $teachingPeriod['updated_at_label'] }}</time>
                                </span>
                            @endif
                        </header>

                        <dl class="tala-calendar-facts">
                            <div class="tala-calendar-facts__item">
                                <dt>Classes run</dt>
                                <dd class="tabular-nums">
                                    @if ($teachingPeriod['classes_start_iso'] && $teachingPeriod['classes_end_iso'])
                                        <time datetime="{{ $teachingPeriod['classes_start_iso'] }}">{{ $teachingPeriod['classes_start_label'] }}</time>
                                        –
                                        <time datetime="{{ $teachingPeriod['classes_end_iso'] }}">{{ $teachingPeriod['classes_end_label'] }}</time>
                                    @else
                                        Dates not set
                                    @endif
                                </dd>
                                @if ($teachingPeriod['admin_start_iso'] && $teachingPeriod['admin_end_iso'])
                                    <dd class="tala-calendar-facts__note tabular-nums">
                                        Term dates:
                                        <time datetime="{{ $teachingPeriod['admin_start_iso'] }}">{{ $teachingPeriod['admin_start_label'] }}</time>
                                        –
                                        <time datetime="{{ $teachingPeriod['admin_end_iso'] }}">{{ $teachingPeriod['admin_end_label'] }}</time>
                                    </dd>
                                @endif
                            </div>

                            <div class="tala-calendar-facts__item">
                                <dt>Approved teaching hours</dt>
                                <dd class="tabular-nums">{{ $teachingPeriod['teaching_hours'] }}</dd>
                                <dd class="tala-calendar-facts__note">{{ $teachingPeriod['teaching_hours_note'] }}</dd>
                            </div>

                            @if ($teachingPeriod['special_term_basis'])
                                <div class="tala-calendar-facts__item">
                                    <dt>Special term basis</dt>
                                    <dd>{{ $teachingPeriod['special_term_basis'] }}</dd>
                                </div>
                            @endif
                        </dl>

                        @include('filament.pages.partials.term-teaching-week', ['teachingWeekRows' => $teachingWeekRows])
                    </section>

                    <section class="tala-task-surface tala-calendar-activities tala-faculty-calendar-windows" aria-labelledby="faculty-academic-windows-heading">
                        <header class="tala-calendar-sheet-heading">
                            <div class="tala-calendar-sheet-heading__title">
                                <h2 id="faculty-academic-windows-heading">
                                    <x-filament::icon icon="heroicon-o-clock" aria-hidden="true" />
                                    <span>Academic windows</span>
                                </h2>
                            </div>
                        </header>
                        <p class="tala-work-footnote">
                            Official enrollment, adjustment, examination, and grade entry periods set by the Registrar.
                        </p>

                        <dl class="tala-calendar-activity-list">
                            @foreach ($academicWindows as $window)
                                <div @class(['tala-calendar-activity', 'is-recorded' => $window['opens_on_iso'] !== null, 'is-unset' => $window['opens_on_iso'] === null])>
                                    <dt>
                                        <x-filament::icon :icon="$window['icon']" aria-hidden="true" />
                                        <span class="tala-calendar-activity__label">
                                            <span>{{ $window['label'] }}</span>
                                            @if ($window['note'])
                                                <small>{{ $window['note'] }}</small>
                                            @endif
                                        </span>
                                    </dt>
                                    <dd>
                                        @if ($window['opens_on_iso'] && $window['closes_on_iso'])
                                            <div class="tala-calendar-activity-period">
                                                <strong class="tabular-nums">
                                                    <time datetime="{{ $window['opens_on_iso'] }}">{{ $window['opens_on_label'] }}</time>
                                                    –
                                                    <time datetime="{{ $window['closes_on_iso'] }}">{{ $window['closes_on_label'] }}</time>
                                                </strong>
                                                @if ($window['cutoff_label'])
                                                    <small class="tabular-nums">
                                                        <time datetime="{{ $window['closes_at_iso'] }}">{{ $window['cutoff_label'] }}</time>
                                                    </small>
                                                @endif
                                            </div>
                                            <x-filament::badge :color="$window['status_color']" size="sm">{{ $window['status'] }}</x-filament::badge>
                                        @else
                                            <span class="tala-calendar-unset">Not set</span>
                                        @endif
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                </div>

                <section class="tala-task-surface tala-calendar-activities tala-faculty-calendar-holidays" aria-labelledby="faculty-dated-exceptions-heading">
                    <header class="tala-calendar-sheet-heading">
                        <div class="tala-calendar-sheet-heading__title">
                            <h2 id="faculty-dated-exceptions-heading">
                                <x-filament::icon icon="heroicon-o-calendar-days" aria-hidden="true" />
                                <span>No-class holidays &amp; dated exceptions</span>
                            </h2>
                        </div>
                    </header>

                    @if ($datedExceptions['upcoming'] === [] && $datedExceptions['past'] === [])
                        <p class="tala-work-footnote">No holidays or dated calendar exceptions are recorded for this term.</p>
                    @else
                        @if ($datedExceptions['upcoming'] !== [])
                            <div class="tala-calendar-exceptions">
                                <h3>Upcoming dates</h3>
                                <ul>
                                    @foreach ($datedExceptions['upcoming'] as $exception)
                                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2 border-b border-gray-200 dark:border-gray-800 last:border-b-0">
                                            <div class="min-w-0 space-y-0.5">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <strong class="font-medium">{{ $exception['label'] }}</strong>
                                                    @if ($exception['type_label'])
                                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $exception['type_label'] }}</span>
                                                    @endif
                                                </div>
                                                <p class="text-sm text-gray-600 dark:text-gray-300 tabular-nums">
                                                    @if ($exception['is_same_day'] && $exception['starts_on_iso'])
                                                        <time datetime="{{ $exception['starts_on_iso'] }}">{{ $exception['starts_on_label'] }}</time>
                                                    @elseif ($exception['starts_on_iso'] && $exception['ends_on_iso'])
                                                        <time datetime="{{ $exception['starts_on_iso'] }}">{{ $exception['starts_on_label'] }}</time>
                                                        –
                                                        <time datetime="{{ $exception['ends_on_iso'] }}">{{ $exception['ends_on_label'] }}</time>
                                                    @endif
                                                </p>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                @if ($exception['timing_label'])
                                                    <x-filament::badge :color="$exception['timing_color']" size="sm">
                                                        {{ $exception['timing_label'] }}
                                                    </x-filament::badge>
                                                @endif
                                                <x-filament::badge :color="$exception['blocks_teaching'] ? 'warning' : 'gray'" size="sm">
                                                    {{ $exception['blocks_teaching'] ? 'No classes' : 'Classes continue' }}
                                                </x-filament::badge>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($datedExceptions['past'] !== [])
                            <div class="tala-calendar-exceptions">
                                <h3>{{ $datedExceptions['upcoming'] !== [] ? 'Earlier this term' : 'Recorded dates' }}</h3>
                                <ul>
                                    @foreach ($datedExceptions['past'] as $exception)
                                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-2 border-b border-gray-200 dark:border-gray-800 last:border-b-0">
                                            <div class="min-w-0 space-y-0.5">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <strong class="font-medium">{{ $exception['label'] }}</strong>
                                                    @if ($exception['type_label'])
                                                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ $exception['type_label'] }}</span>
                                                    @endif
                                                </div>
                                                <p class="text-sm text-gray-600 dark:text-gray-300 tabular-nums">
                                                    @if ($exception['is_same_day'] && $exception['starts_on_iso'])
                                                        <time datetime="{{ $exception['starts_on_iso'] }}">{{ $exception['starts_on_label'] }}</time>
                                                    @elseif ($exception['starts_on_iso'] && $exception['ends_on_iso'])
                                                        <time datetime="{{ $exception['starts_on_iso'] }}">{{ $exception['starts_on_label'] }}</time>
                                                        –
                                                        <time datetime="{{ $exception['ends_on_iso'] }}">{{ $exception['ends_on_label'] }}</time>
                                                    @endif
                                                </p>
                                            </div>
                                            <x-filament::badge :color="$exception['blocks_teaching'] ? 'warning' : 'gray'" size="sm">
                                                {{ $exception['blocks_teaching'] ? 'No classes' : 'Classes continue' }}
                                            </x-filament::badge>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @endif
                </section>
            @endif
        @endif
    </div>
</x-filament-panels::page>
