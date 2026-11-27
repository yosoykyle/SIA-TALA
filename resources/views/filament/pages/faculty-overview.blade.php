<x-filament-panels::page>
    <div class="tala-faculty-workflow tala-faculty-overview-layout space-y-4">
        @if (! $term)
            <x-servitech-empty-state
                illustrated
                heading="No active term yet"
                description="Your teaching overview, weekly schedule, and classes appear here once the Registrar activates a term."
            >
                @foreach ($noTermAttention as $attention)
                    <x-filament::button
                        tag="a"
                        color="gray"
                        size="sm"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                        :href="$attention['href']"
                    >
                        {{ $attention['label'] }} ({{ $attention['count'] }})
                    </x-filament::button>
                @endforeach
            </x-servitech-empty-state>
        @else
            <x-record-scope-bar
                label="Current term"
                icon="heroicon-o-calendar-days"
                :scope-title="$scopeTitle"
                :scope-meta="$scopeMeta"
            />

            @if ($actionItems !== [])
                <section class="tala-task-surface overflow-hidden" aria-labelledby="faculty-needs-action-heading">
                    <header class="p-4 sm:px-5 border-b border-gray-200 dark:border-gray-800 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                        <div>
                            <h2 id="faculty-needs-action-heading" class="flex items-center gap-2 text-base font-semibold">
                                Needs your action
                                <x-filament::badge color="gray" class="tabular-nums">{{ count($actionItems) }}</x-filament::badge>
                            </h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Each item names the affected class or task and opens the direct place to handle it.</p>
                        </div>
                    </header>
                    <ul class="list-none m-0 p-0 divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($actionItems as $item)
                            <li class="p-4 sm:px-5 flex flex-wrap items-center justify-between gap-x-5 gap-y-3">
                                <div class="flex-1 basis-96 min-w-0 grid gap-1.5">
                                    <div>
                                        <x-filament::badge :color="$item['severity']">{{ $item['badge'] }}</x-filament::badge>
                                    </div>
                                    <h3 class="text-base font-semibold">{{ $item['title'] }}</h3>
                                    <p class="text-sm text-gray-500 dark:text-gray-400">{{ $item['summary'] }}</p>
                                </div>
                                <div class="flex flex-wrap gap-2 items-center">
                                    <x-filament::button
                                        tag="a"
                                        :color="$item['action_color']"
                                        size="sm"
                                        icon="heroicon-m-arrow-right"
                                        icon-position="after"
                                        :href="$item['action_url']"
                                    >
                                        {{ $item['action_label'] }}
                                    </x-filament::button>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section class="tala-task-surface tala-faculty-overview-calendar overflow-hidden" aria-labelledby="faculty-overview-schedule-heading">
                <header class="p-4 sm:px-5 border-b border-gray-200 dark:border-gray-800 flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
                    <div class="min-w-0">
                        <h2 id="faculty-overview-schedule-heading" class="text-base font-semibold text-gray-950 dark:text-white">
                            Weekly schedule
                        </h2>
                        @if (($scheduleSummary['totalClassCount'] ?? 0) > 0)
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5 tabular-nums">
                                {{ $scheduleSummary['totalClassCount'] }} {{ \Illuminate\Support\Str::plural('class', $scheduleSummary['totalClassCount']) }}
                                @if ($scheduleSummary['meetingCount'] > 0)
                                    · {{ $scheduleSummary['meetingCount'] }} weekly {{ \Illuminate\Support\Str::plural('meeting', $scheduleSummary['meetingCount']) }}
                                @endif
                                @if ($scheduleSummary['externallyArrangedCount'] > 0)
                                    · {{ $scheduleSummary['externallyArrangedCount'] }} externally arranged
                                @endif
                            </p>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::link
                            :href="$scheduleSummary['academicCalendarUrl']"
                            color="gray"
                            size="sm"
                        >
                            Academic calendar
                        </x-filament::link>
                        <x-filament::button
                            tag="a"
                            :href="$scheduleSummary['url']"
                            color="gray"
                            size="sm"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                        >
                            Open full schedule
                        </x-filament::button>
                    </div>
                </header>

                @if ($schedulePresentation !== null)
                    <div class="p-3 sm:p-4">
                        <x-tala-timetable
                            :presentation="$schedulePresentation"
                            focused
                            controlled
                            embedded
                            :show-window="false"
                        />
                    </div>
                @elseif ($externallyArrangedClasses === [])
                    <div class="p-4 sm:p-6">
                        <x-servitech-empty-state
                            icon="heroicon-o-calendar-days"
                            heading="Schedule not published yet"
                            description="Your weekly teaching timetable appears here once the Registrar publishes the official schedule for this term."
                        />
                    </div>
                @endif

                @if ($externallyArrangedClasses !== [])
                    <div @class(['p-4 sm:px-5 space-y-2.5', 'border-t border-gray-200 dark:border-gray-800' => $schedulePresentation !== null])>
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-sm font-semibold text-gray-950 dark:text-white">Externally arranged classes</h3>
                            <x-filament::badge color="gray" size="sm" class="tabular-nums">
                                {{ count($externallyArrangedClasses) }} externally arranged {{ \Illuminate\Support\Str::plural('class', count($externallyArrangedClasses)) }}
                            </x-filament::badge>
                        </div>
                        <ul class="list-none m-0 p-0 divide-y divide-gray-200 dark:divide-gray-800">
                            @foreach ($externallyArrangedClasses as $external)
                                <li class="py-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
                                    <div class="min-w-0">
                                        <span class="font-medium text-gray-950 dark:text-white">{{ $external['course_title'] }}</span>
                                        <span class="text-xs text-gray-500 dark:text-gray-400"> · {{ $external['course_code'] }} · {{ $external['section_code'] }}</span>
                                    </div>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $external['role'] }} · No fixed weekly room</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>

            <nav class="tala-faculty-overview-actions" aria-label="Quick actions">
                <x-workspace-card
                    compact
                    :href="$availabilityCard['url']"
                    icon="heroicon-o-clock"
                    label="My availability"
                    :description="$availabilityCard['statusLabel']"
                    :note="$availabilityCard['note']"
                />
                <x-workspace-card
                    compact
                    :href="$gradesCard['url']"
                    icon="heroicon-o-academic-cap"
                    label="Class list & grades"
                    :description="$gradesCard['description']"
                    :note="$gradesCard['note']"
                    :count="$gradesCard['actionableCount'] > 0 ? $gradesCard['actionableCount'] : null"
                />
            </nav>
        @endif
    </div>
</x-filament-panels::page>
