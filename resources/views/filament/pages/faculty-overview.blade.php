<x-filament-panels::page>
    <div class="tala-faculty-workflow space-y-4">
        @if (! $term)
            <x-servitech-term-gate
                heading="{{ $terms->isEmpty() ? 'No terms available yet' : 'Choose a term to view your overview' }}"
                description="{{ $terms->isEmpty() ? 'Teaching terms appear here once the Registrar creates a term.' : 'Select an academic year and term to see your schedule, availability, and class grades.' }}"
                icon="heroicon-o-calendar-days"
                heading-id="faculty-overview-term-gate-heading"
                :attention="$termGateAttention"
                attention-label="Class grades needing your attention"
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

            @if ($actionItems !== [])
                <section class="tala-task-surface overflow-hidden" aria-labelledby="faculty-needs-action-heading">
                    <header class="p-5 border-b border-gray-200 dark:border-gray-800 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-2">
                        <div>
                            <h2 id="faculty-needs-action-heading" class="flex items-center gap-2 text-base font-semibold">
                                Needs your action
                                <x-filament::badge color="gray">{{ count($actionItems) }}</x-filament::badge>
                            </h2>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Each item names the affected class or task and opens the direct place to handle it.</p>
                        </div>
                    </header>
                    <ul class="list-none m-0 p-0 divide-y divide-gray-200 dark:divide-gray-800">
                        @foreach ($actionItems as $item)
                            <li class="p-5 flex flex-wrap items-center justify-between gap-x-5 gap-y-3">
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

            <ul class="tala-workflow-steps tala-workflow-steps--three" aria-label="Faculty tasks">
                <li @class(['tala-workflow-step', 'tala-workflow-step--priority' => $priorityCard === 'schedule'])>
                    <div class="tala-workflow-step__body">
                        <div class="tala-workflow-step__head">
                            <span class="tala-workspace-card__icon size-8" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                            </span>
                        </div>
                        <h2 class="tala-workflow-step__title">My schedule</h2>
                        @if (($scheduleCard['totalClassCount'] ?? 0) > 0)
                            <div class="tala-workflow-step__figure">
                                <x-workspace-figure :value="$scheduleCard['totalClassCount']" :label="\Illuminate\Support\Str::plural('class', $scheduleCard['totalClassCount'])" />
                            </div>
                            @if ($scheduleCard['meetingCount'] > 0)
                                <p class="tala-workflow-step__desc">
                                    {{ $scheduleCard['meetingCount'] }} weekly {{ \Illuminate\Support\Str::plural('meeting', $scheduleCard['meetingCount']) }}@if (($scheduleCard['externallyArrangedCount'] ?? 0) > 0) · {{ $scheduleCard['externallyArrangedCount'] }} externally arranged @endif
                                </p>
                            @else
                                <p class="tala-workflow-step__desc">{{ $scheduleCard['externallyArrangedCount'] }} externally arranged {{ \Illuminate\Support\Str::plural('class', $scheduleCard['externallyArrangedCount']) }}</p>
                                <p class="tala-workflow-step__meta">OJT, practicum, or externally arranged subjects with no fixed weekly room slot.</p>
                            @endif
                        @else
                            <p class="tala-workflow-step__desc">Not published yet</p>
                            <p class="tala-workflow-step__meta">Your weekly teaching meetings appear here once the timetable is published.</p>
                        @endif
                        @if ($scheduleCard['examPeriodLabel'] !== null)
                            <p class="tala-workflow-step__meta">Exam period: {{ $scheduleCard['examPeriodLabel'] }}</p>
                        @endif
                        @if ($scheduleCard['affectedCount'] > 0)
                            <p class="tala-workflow-step__meta">{{ $scheduleCard['affectedCount'] }} {{ \Illuminate\Support\Str::plural('meeting', $scheduleCard['affectedCount']) }} affected by recent changes</p>
                        @endif
                    </div>
                    <div class="tala-workflow-step__action">
                        <x-filament::button
                            tag="a"
                            :href="$scheduleCard['url']"
                            :color="$priorityCard === 'schedule' ? 'primary' : 'gray'"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                        >
                            Open schedule
                        </x-filament::button>
                    </div>
                </li>

                <li @class(['tala-workflow-step', 'tala-workflow-step--priority' => $priorityCard === 'availability'])>
                    <div class="tala-workflow-step__body">
                        <div class="tala-workflow-step__head">
                            <span class="tala-workspace-card__icon size-8" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5" />
                            </span>
                        </div>
                        <h2 class="tala-workflow-step__title">My availability</h2>
                        <p class="tala-workflow-step__desc font-semibold text-gray-950 dark:text-white">{{ $availabilityCard['statusLabel'] }}</p>
                        <p class="tala-workflow-step__meta">{{ $availabilityCard['description'] }}</p>
                        @if ($availabilityCard['replyBy'] !== null)
                            <p class="tala-workflow-step__meta">Reply by {{ $availabilityCard['replyBy'] }}</p>
                        @endif
                    </div>
                    <div class="tala-workflow-step__action">
                        <x-filament::button
                            tag="a"
                            :href="$availabilityCard['url']"
                            :color="$priorityCard === 'availability' ? 'primary' : 'gray'"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                        >
                            {{ $availabilityCard['ctaLabel'] }}
                        </x-filament::button>
                    </div>
                </li>

                <li @class(['tala-workflow-step', 'tala-workflow-step--priority' => $priorityCard === 'grades'])>
                    <div class="tala-workflow-step__body">
                        <div class="tala-workflow-step__head">
                            <span class="tala-workspace-card__icon size-8" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-academic-cap" class="h-5 w-5" />
                            </span>
                        </div>
                        <h2 class="tala-workflow-step__title">Class grades</h2>
                        <div class="tala-workflow-step__figure">
                            <x-workspace-figure :value="$gradesCard['actionableCount']" :label="\Illuminate\Support\Str::plural('class', $gradesCard['actionableCount']).' needing action'" />
                        </div>
                        <p class="tala-workflow-step__meta">{{ $gradesCard['assignedCount'] }} assigned {{ \Illuminate\Support\Str::plural('class', $gradesCard['assignedCount']) }}</p>
                        @if ($gradesCard['openIncCount'] > 0)
                            <p class="tala-workflow-step__meta">{{ $gradesCard['openIncCount'] }} open INC {{ \Illuminate\Support\Str::plural('completion', $gradesCard['openIncCount']) }}</p>
                        @endif
                        @if ($gradesCard['otherTermActionableCount'] > 0)
                            <p class="tala-workflow-step__meta">{{ $gradesCard['otherTermActionableCount'] }} {{ \Illuminate\Support\Str::plural('class', $gradesCard['otherTermActionableCount']) }} needing action in earlier terms</p>
                        @endif
                    </div>
                    <div class="tala-workflow-step__action">
                        <x-filament::button
                            tag="a"
                            :href="$gradesCard['url']"
                            :color="$priorityCard === 'grades' ? 'primary' : 'gray'"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                        >
                            Open class grades
                        </x-filament::button>
                    </div>
                </li>
            </ul>
        @endif
    </div>
</x-filament-panels::page>
