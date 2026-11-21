<x-filament-panels::page>
    <div class="tala-faculty-workflow space-y-4">
        @if (! $term)
            @include('filament.components.faculty-term-context', [
                'emptyHeading' => 'Choose a Term to view your schedule',
                'emptyDescription' => 'Select an Academic Year and Term to see your official teaching meetings.',
            ])
        @else
            @include('filament.components.faculty-term-context')

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

            @if ($assignmentCount === 0)
                <x-servitech-empty-state
                    illustrated
                    heading="No official meetings assigned in this Term"
                    description="Meetings appear here when the Registrar publishes your timetable."
                >
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\MyAvailability::getUrl(['termId' => $term->id])">
                        My availability
                    </x-filament::button>
                </x-servitech-empty-state>
            @else
                <dl class="tala-faculty-schedule-figures" aria-label="Schedule summary">
                    <div><dt>Classes</dt><dd>{{ $classCount }}</dd></div>
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
                        <p class="tala-faculty-schedule-bar__tz">Times in Asia/Manila</p>
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
        @endif
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
