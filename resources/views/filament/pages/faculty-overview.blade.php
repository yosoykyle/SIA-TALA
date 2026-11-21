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
                <section class="tala-task-surface" style="overflow: hidden;" aria-labelledby="faculty-needs-action-heading">
                    <header style="padding: var(--tala-card-pad); border-block-end: 1px solid var(--tala-edge); display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: 0.5rem 1rem;">
                        <div>
                            <h2 id="faculty-needs-action-heading" style="display: flex; align-items: center; gap: 0.5rem; font-size: var(--tala-type-section); font-weight: var(--tala-weight-heading);">
                                Needs your action
                                <x-filament::badge color="gray">{{ count($actionItems) }}</x-filament::badge>
                            </h2>
                            <p style="color: var(--tala-muted); font-size: var(--tala-type-caption);">Each item names the affected class or task and opens the direct place to handle it.</p>
                        </div>
                    </header>
                    <ul style="list-style: none; margin: 0; padding: 0;">
                        @foreach ($actionItems as $item)
                            <li style="padding: var(--tala-card-pad); display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem 1.25rem; @if (! $loop->first) border-block-start: 1px solid var(--tala-edge); @endif">
                                <div style="flex: 1 1 24rem; min-inline-size: 0; display: grid; gap: 0.375rem;">
                                    <div>
                                        <x-filament::badge :color="$item['severity']">{{ $item['badge'] }}</x-filament::badge>
                                    </div>
                                    <h3 style="font-size: 1rem; font-weight: 600;">{{ $item['title'] }}</h3>
                                    <p style="color: var(--tala-muted); font-size: 0.875rem;">{{ $item['summary'] }}</p>
                                </div>
                                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center;">
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
                            <span class="tala-workspace-card__icon" style="inline-size: 2rem; block-size: 2rem;" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-calendar-days" style="inline-size: 1.125rem; block-size: 1.125rem;" />
                            </span>
                        </div>
                        <h2 class="tala-workflow-step__title">My schedule</h2>
                        @if ($scheduleCard['meetingCount'] > 0)
                            <div class="tala-workflow-step__figure">
                                <x-workspace-figure :value="$scheduleCard['classCount']" :label="\Illuminate\Support\Str::plural('class', $scheduleCard['classCount'])" />
                            </div>
                            <p class="tala-workflow-step__desc">{{ $scheduleCard['classCount'] }} {{ \Illuminate\Support\Str::plural('class', $scheduleCard['classCount']) }} · {{ $scheduleCard['meetingCount'] }} weekly {{ \Illuminate\Support\Str::plural('meeting', $scheduleCard['meetingCount']) }}</p>
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
                            <span class="tala-workspace-card__icon" style="inline-size: 2rem; block-size: 2rem;" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-clock" style="inline-size: 1.125rem; block-size: 1.125rem;" />
                            </span>
                        </div>
                        <h2 class="tala-workflow-step__title">My availability</h2>
                        <p class="tala-workflow-step__desc" style="color: var(--tala-text); font-weight: 600;">{{ $availabilityCard['statusLabel'] }}</p>
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
                            <span class="tala-workspace-card__icon" style="inline-size: 2rem; block-size: 2rem;" aria-hidden="true">
                                <x-filament::icon icon="heroicon-o-academic-cap" style="inline-size: 1.125rem; block-size: 1.125rem;" />
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
