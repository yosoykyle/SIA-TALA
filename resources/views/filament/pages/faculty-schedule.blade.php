<x-filament-panels::page>
    <div class="tala-faculty-workflow space-y-4">
        @if (! $term)
            <x-servitech-term-gate
                heading="{{ $terms->isEmpty() ? 'No terms available yet' : 'Choose a term to view your schedule' }}"
                description="{{ $terms->isEmpty() ? 'No term is available. Contact the Registrar.' : 'Select an academic year and term to see your official teaching meetings.' }}"
                icon="heroicon-o-calendar-days"
                heading-id="faculty-term-chooser-heading"
            >
                @if ($terms->isNotEmpty())
                    {{ $this->termSelection }}
                @endif
            </x-servitech-term-gate>
        @else
            <x-record-scope-bar
                label="Selected term"
                icon="heroicon-o-calendar-days"
                :scope-meta="$scopeMeta"
            >
                {{ $this->termSelection }}
            </x-record-scope-bar>

            @if ($termTeachingRange !== null || $examinationPeriod !== null || $upcomingHolidays !== [])
                <section class="tala-context-inset flex flex-wrap items-center justify-between gap-x-6 gap-y-2 text-sm" aria-label="Term dates and upcoming holidays">
                    <div class="flex flex-wrap items-center gap-x-5 gap-y-1 tabular-nums">
                        @if ($termTeachingRange !== null)
                            <span>
                                <strong class="font-medium text-gray-950 dark:text-white">Classes:</strong>
                                <span class="text-gray-600 dark:text-gray-300">{{ $termTeachingRange }}</span>
                            </span>
                        @endif
                        <span>
                            <strong class="font-medium text-gray-950 dark:text-white">Exam period:</strong>
                            <span class="text-gray-600 dark:text-gray-300">
                                {{ $examinationPeriod ? $examinationPeriod->opens_on->format('M j').'–'.$examinationPeriod->closes_on->format('M j, Y') : 'Not configured' }}
                            </span>
                        </span>
                    </div>
                    @if ($upcomingHolidays !== [])
                        <div class="flex flex-wrap items-center gap-1.5">
                            <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Upcoming no-class dates:</span>
                            @foreach ($upcomingHolidays as $holiday)
                                <x-filament::badge color="gray" size="sm" class="tabular-nums">
                                    {{ $holiday['date_label'] }} · {{ $holiday['label'] }}
                                </x-filament::badge>
                            @endforeach
                        </div>
                    @endif
                </section>
            @endif

            @if ($affectedMeetings !== [])
                <section class="tala-warning-banner" role="status" aria-labelledby="faculty-affected-changes-heading">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="space-y-1">
                            <h2 id="faculty-affected-changes-heading" class="flex items-center gap-2 text-sm font-semibold">
                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" aria-hidden="true" />
                                <span>Affected by recent change</span>
                            </h2>
                            <p class="text-sm">
                                {{ count($affectedMeetings) === 1 ? '1 of your classes is affected by a recent change.' : count($affectedMeetings).' of your classes are affected by recent changes.' }}
                                @if ($hasLateAvailabilityImpact)
                                    Current classes stay as scheduled until the Registrar publishes an update.
                                @endif
                            </p>
                        </div>
                        @if ($hasLateAvailabilityImpact)
                            <x-filament::button tag="a" color="gray" size="sm" :href="\App\Filament\Pages\MyAvailability::getUrl(['termId' => $term->id])">
                                Update availability
                            </x-filament::button>
                        @endif
                    </div>
                    <ul class="tala-faculty-affected-list mt-1">
                        @foreach ($affectedMeetings as $affected)
                            <li class="tala-faculty-affected-row">
                                <div class="tala-faculty-affected-row__main">
                                    <x-filament::badge :color="$affected['badge_color']" size="sm">
                                        {{ $affected['badge_label'] }}
                                    </x-filament::badge>
                                    <strong>{{ $affected['course_section'] }}</strong>
                                    <span class="tabular-nums">{{ $affected['schedule_line'] }}</span>
                                </div>
                                <span class="tala-faculty-affected-row__detail">{{ $affected['detail_line'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($assignmentCount === 0 && ($externallyArrangedClasses ?? []) === [])
                <x-servitech-empty-state
                    illustrated
                    heading="No official meetings assigned in this term"
                    description="Meetings appear here when the Registrar publishes your timetable."
                >
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\MyAvailability::getUrl(['termId' => $term->id])">
                        My availability
                    </x-filament::button>
                </x-servitech-empty-state>
            @else
                @php
                    $externalClassCount = count($externallyArrangedClasses ?? []);
                    $resolvedTotalClassCount = $totalClassCount ?? ($classCount + $externalClassCount);
                @endphp
                <dl class="tala-faculty-schedule-figures" aria-label="Schedule summary">
                    <div>
                        <dt>Classes</dt>
                        <dd>{{ $resolvedTotalClassCount }}</dd>
                        @if ($externalClassCount > 0)
                            <span class="tala-faculty-schedule-figures__caption">
                                {{ $externalClassCount === $resolvedTotalClassCount ? ($externalClassCount === 1 ? '1 externally arranged' : $externalClassCount.' externally arranged') : 'Includes '.$externalClassCount.' externally arranged' }}
                            </span>
                        @endif
                    </div>
                    <div><dt>Weekly meetings</dt><dd>{{ $assignmentCount }}</dd></div>
                    <div><dt>Teaching days</dt><dd>{{ $teachingDayCount }}</dd></div>
                    <div class="tala-faculty-schedule-figures__exam">
                        <dt>Exam period</dt>
                        <dd>
                            @if ($examinationPeriod)
                                {{ $examinationPeriod->opens_on->format('M j') }}–{{ $examinationPeriod->closes_on->format('M j, Y') }}
                            @else
                                Dates not configured
                            @endif
                        </dd>
                        <span class="tala-faculty-schedule-figures__caption">
                            @if ($examinationPeriod)
                                Arrange exam times directly with your classes.
                            @else
                                Contact the Registrar for approved dates.
                            @endif
                        </span>
                    </div>
                </dl>

                @if ($assignmentCount > 0)
                    <section class="tala-task-surface tala-schedule-surface tala-schedule-work {{ $scheduleView === 'week' ? 'tala-schedule-week' : 'tala-schedule-list' }}" aria-label="Official teaching schedule">
                        <div class="tala-faculty-schedule-bar">
                            <x-tala-navigation label="Schedule view" compact>
                                <x-filament::tabs.item
                                    :active="$scheduleView === 'week'"
                                    icon="heroicon-o-calendar-days"
                                    wire:click="setScheduleView('week')"
                                >
                                    Weekly
                                </x-filament::tabs.item>
                                <x-filament::tabs.item
                                    :active="$scheduleView === 'list'"
                                    icon="heroicon-o-list-bullet"
                                    wire:click="setScheduleView('list')"
                                >
                                    List
                                </x-filament::tabs.item>
                            </x-tala-navigation>
                        </div>

                        @if ($this->scheduleCriteria() !== [])
                            <p class="tala-schedule-filter-notice" role="status">
                                Showing filtered classes: {{ $this->scheduleCriteriaDescription() }}.
                                <button type="button" wire:click="clearScheduleFilters">Clear filters</button>
                            </p>
                        @endif

                        {{ $this->table }}
                    </section>
                @endif

                @if (($externallyArrangedClasses ?? []) !== [])
                    <section class="tala-task-surface p-5 space-y-3" aria-labelledby="faculty-externally-arranged-heading">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <div>
                                <h2 id="faculty-externally-arranged-heading" class="text-base font-semibold">Externally arranged classes</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    Practicum, OJT, or externally arranged subjects assigned to you in this term with no fixed weekly classroom slot.
                                </p>
                            </div>
                            <x-filament::badge color="gray" size="sm">
                                {{ count($externallyArrangedClasses) }} {{ count($externallyArrangedClasses) === 1 ? 'class' : 'classes' }}
                            </x-filament::badge>
                        </div>

                        <div class="tala-data-table-scroll" role="region" aria-labelledby="faculty-externally-arranged-heading" tabindex="0">
                            <table class="tala-data-table w-full text-left text-sm">
                                <thead>
                                    <tr>
                                        <th scope="col">Subject</th>
                                        <th scope="col">Class</th>
                                        <th scope="col">Role</th>
                                        <th scope="col">Units</th>
                                        <th scope="col">Students</th>
                                        <th scope="col">Arrangement</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($externallyArrangedClasses as $item)
                                        <tr wire:key="faculty-external-class-{{ $loop->index }}">
                                            <th scope="row" class="font-medium">
                                                <div>{{ $item['course_title'] }}</div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400">{{ $item['course_code'] }}</div>
                                            </th>
                                            <td>{{ $item['section_code'] }}</td>
                                            <td>{{ $item['role'] }}</td>
                                            <td class="tabular-nums">{{ $item['units'] }}</td>
                                            <td class="tabular-nums">{{ $item['enrolled_count'] }}</td>
                                            <td>{{ $item['note'] }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </section>
                @endif
            @endif
        @endif
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
