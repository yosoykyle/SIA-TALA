<div class="tala-program-panel__header">
    <div class="tala-program-panel__heading">
        <h2 class="tala-program-panel__title">Year levels &amp; sections</h2>
        <p class="tala-program-panel__description">
            Active year levels, student batches, sections, and timetable readiness for {{ $program->code }} in the selected term.
        </p>
    </div>

    @if ($terms->isNotEmpty())
        <div class="tala-program-panel__control shrink-0">
            <label for="program-sections-term-select" class="tala-program-panel__control-label">
                Term / SY
            </label>
            <x-filament::input.wrapper class="min-w-52">
                <x-filament::input.select
                    id="program-sections-term-select"
                    wire:model.live="selectedTermId"
                >
                    @foreach ($terms as $termOption)
                        <option value="{{ $termOption->id }}">
                            {{ $termOption->label }}@if ($termOption->academicYear?->label) ({{ $termOption->academicYear->label }})@endif @if ($termOption->state === \App\Models\Term::StateActive) · Active @endif
                        </option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
    @endif
</div>

@if (! $selectedTerm)
    <x-servitech-empty-state
        :illustrated="true"
        icon="heroicon-o-calendar-days"
        heading="No academic terms configured yet"
        description="Create an academic year and term to track running year levels and sections."
        heading-tag="h3"
    />
@elseif ($yearLevelRows === [])
    <x-servitech-empty-state
        :illustrated="false"
        icon="heroicon-o-user-group"
        heading="No active year levels in this term"
        :description="'No student sections or officially enrolled students are recorded for ' . $program->code . ' in ' . $selectedTerm->label . '.'"
        heading-tag="h3"
    >
        @if ($sectionsSummary['term_planning_url'])
            <x-filament::button
                tag="a"
                :href="$sectionsSummary['term_planning_url']"
                color="gray"
                size="sm"
                icon="heroicon-m-arrow-top-right-on-square"
                icon-position="after"
            >
                Open Term Planning
            </x-filament::button>
        @endif
    </x-servitech-empty-state>
@else
    <div class="tala-sections-overview" aria-label="Term readiness summary">
        <div class="tala-sections-overview__summary">
            <div class="tala-sections-overview__stats tabular-nums">
                <span class="tala-sections-overview__stat">
                    <strong>{{ $sectionsSummary['year_levels_count'] }}</strong>
                    <span>{{ \Illuminate\Support\Str::plural('year level', $sectionsSummary['year_levels_count']) }}</span>
                </span>

                <span class="tala-sections-overview__stat">
                    <strong>{{ $sectionsSummary['sections_count'] }}</strong>
                    <span>{{ \Illuminate\Support\Str::plural('section', $sectionsSummary['sections_count']) }}</span>
                </span>

                <span class="tala-sections-overview__stat">
                    @if ($sectionsSummary['has_planned_capacity'])
                        <strong>{{ number_format($sectionsSummary['enrolled_count']) }} / {{ number_format($sectionsSummary['planned_capacity']) }}</strong>
                        <span>class places filled</span>
                    @else
                        <strong>{{ number_format($sectionsSummary['enrolled_count']) }}</strong>
                        <span>{{ \Illuminate\Support\Str::plural('student', $sectionsSummary['enrolled_count']) }} enrolled</span>
                    @endif
                </span>
            </div>

            @if ($sectionsSummary['sections_count'] > 0)
                @if ($sectionsSummary['published_sections_count'] === $sectionsSummary['sections_count'])
                    <x-filament::badge color="success" size="sm" icon="heroicon-m-check-circle">
                        All scheduled
                    </x-filament::badge>
                @else
                    <x-filament::badge color="warning" size="sm" icon="heroicon-m-calendar" class="tabular-nums">
                        {{ $sectionsSummary['published_sections_count'] }} / {{ $sectionsSummary['sections_count'] }} scheduled
                    </x-filament::badge>
                @endif
            @endif
        </div>

        @if ($sectionsSummary['term_planning_url'])
            <x-filament::button
                tag="a"
                :href="$sectionsSummary['term_planning_url']"
                size="sm"
                color="gray"
                icon="heroicon-m-arrow-top-right-on-square"
                icon-position="after"
            >
                Open Term Planning
            </x-filament::button>
        @endif
    </div>

    @php
        $firstYearKey = $yearLevelRows[0]['year_key'] ?? null;
    @endphp

    <div
        class="tala-program-panel__body"
        x-data="{
            expanded: {
                @if ($firstYearKey !== null)
                    '{{ $firstYearKey }}': true
                @endif
            },
            toggle(key) {
                this.expanded[key] = !this.expanded[key];
            }
        }"
    >
        <div class="tala-year-groups">
            @foreach ($yearLevelRows as $yearRow)
                @php
                    $drilldownId = "year-level-drilldown-{$selectedTerm->id}-{$yearRow['year_key']}";
                @endphp
                <article
                    wire:key="year-level-card-{{ $selectedTerm->id }}-{{ $yearRow['year_key'] }}"
                    class="tala-year-group"
                    x-bind:class="{ 'is-expanded': Boolean(expanded['{{ $yearRow['year_key'] }}']) }"
                >
                    <div
                        class="tala-year-group__header"
                        x-on:click="toggle('{{ $yearRow['year_key'] }}')"
                    >
                        <div class="tala-year-group__lead">
                            <button
                                type="button"
                                class="tala-year-group__trigger"
                                aria-controls="{{ $drilldownId }}"
                                x-bind:aria-expanded="Boolean(expanded['{{ $yearRow['year_key'] }}']).toString()"
                                x-on:click.stop="toggle('{{ $yearRow['year_key'] }}')"
                            >
                                <span
                                    class="tala-program-table__chevron"
                                    x-bind:class="expanded['{{ $yearRow['year_key'] }}'] ? 'is-expanded' : ''"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <span class="tala-year-group__title">{{ $yearRow['year_label'] }}</span>
                            </button>

                            <x-filament::badge :color="$yearRow['status_color']" size="sm">
                                {{ $yearRow['status_label'] }}
                            </x-filament::badge>

                            @if ($yearRow['primary_batch'])
                                <span
                                    class="tala-year-group__chip tabular-nums"
                                    @if ($yearRow['batch_is_planned']) title="Expected cohort batch from linked curriculum" @endif
                                >
                                    {{ $yearRow['primary_batch'] }}
                                </span>
                            @endif

                            @foreach ($yearRow['off_batches'] as $offBatch)
                                <x-filament::badge color="warning" size="sm" class="tabular-nums">
                                    +{{ $offBatch['count'] }} from {{ $offBatch['label'] }}
                                </x-filament::badge>
                            @endforeach

                            @foreach ($yearRow['curricula'] as $curr)
                                <x-filament::button
                                    tag="a"
                                    :href="$curr['url']"
                                    x-on:click.stop
                                    size="sm"
                                    color="gray"
                                    icon="heroicon-m-book-open"
                                    aria-label="Open curriculum {{ $curr['label'] }}"
                                >
                                    {{ $curr['label'] }}
                                </x-filament::button>
                            @endforeach
                        </div>

                        <div class="tala-year-group__stats tabular-nums">
                            <span>
                                <strong>{{ $yearRow['sections_count'] }}</strong>
                                {{ \Illuminate\Support\Str::plural('section', $yearRow['sections_count']) }}
                            </span>
                            <span aria-hidden="true">·</span>
                            <span>
                                <strong>{{ number_format($yearRow['students_count']) }}</strong>
                                enrolled
                            </span>
                        </div>
                    </div>

                    <div
                        id="{{ $drilldownId }}"
                        wire:key="{{ $drilldownId }}"
                        x-show="Boolean(expanded['{{ $yearRow['year_key'] }}'])"
                        x-cloak
                        class="tala-year-group__body"
                    >
                        @if ($yearRow['sections'] === [])
                            <p class="tala-sections-unassigned-notice">
                                <x-filament::icon icon="heroicon-m-information-circle" aria-hidden="true" />
                                <span>
                                    {{ number_format($yearRow['unassigned_students_count']) }} officially enrolled {{ \Illuminate\Support\Str::plural('student', $yearRow['unassigned_students_count']) }} not yet assigned to a student section.
                                </span>
                            </p>
                        @else
                            <x-data-table
                                label="Sections for {{ $yearRow['year_label'] }}"
                                class="tala-sections-drilldown__table"
                            >
                                <thead>
                                    <tr>
                                        <th scope="col">Section</th>
                                        <th scope="col">Enrollment / Capacity</th>
                                        <th scope="col" class="tala-data-table-number">Subjects</th>
                                        <th scope="col" class="text-end tala-sections-schedule-col">Schedule status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($yearRow['sections'] as $section)
                                        @php
                                            $enrolled = (int) $section['enrolled_count'];
                                            $planned = $section['planned_size'];
                                            $fillPercent = ($planned !== null && $planned > 0)
                                                ? min(100, (int) round(($enrolled / $planned) * 100))
                                                : 0;
                                            $capacityState = match (true) {
                                                $planned !== null && $planned > 0 && $enrolled >= $planned => 'is-full',
                                                $fillPercent >= 85 => 'is-high',
                                                default => '',
                                            };
                                            $scheduleIcon = match ($section['schedule_status']) {
                                                'Published' => 'heroicon-m-check-circle',
                                                'Draft' => 'heroicon-m-clock',
                                                default => 'heroicon-m-calendar',
                                            };
                                        @endphp
                                        <tr wire:key="cohort-row-{{ $section['id'] }}">
                                            <td class="whitespace-nowrap">
                                                <span class="tala-section-code font-mono">
                                                    {{ strtoupper($section['reference']) }}
                                                </span>
                                            </td>

                                            <td class="whitespace-nowrap">
                                                <div class="tala-capacity-cell tabular-nums">
                                                    <div class="tala-capacity-cell__text">
                                                        <span class="tala-program-table__primary">{{ number_format($enrolled) }}</span>
                                                        <span class="tala-program-table__muted" aria-hidden="true">/</span>
                                                        <span class="tala-program-table__muted">{{ $planned !== null ? number_format($planned) : '—' }}</span>
                                                    </div>
                                                    @if ($planned !== null && $planned > 0)
                                                        <div class="tala-capacity-meter" aria-hidden="true">
                                                            <div
                                                                @class(['tala-capacity-meter__fill', $capacityState])
                                                                style="width: {{ $fillPercent }}%;"
                                                            ></div>
                                                        </div>
                                                    @endif
                                                </div>
                                            </td>

                                            <td class="tala-data-table-number whitespace-nowrap tabular-nums">
                                                <span class="tala-program-table__primary">{{ $section['subjects_count'] }}</span>
                                                <span class="tala-program-table__muted">{{ \Illuminate\Support\Str::plural('subject', $section['subjects_count']) }}</span>
                                            </td>

                                            <td class="whitespace-nowrap text-end tala-sections-schedule-col">
                                                <div class="tala-sections-schedule-cell">
                                                    <x-filament::badge :color="$section['schedule_color']" :icon="$scheduleIcon" size="sm">
                                                        {{ $section['schedule_status'] }}
                                                    </x-filament::badge>
                                                    @if ($canManage && $section['schedule_status'] === 'No schedule yet' && $sectionsSummary['schedule_url'])
                                                        <x-filament::button
                                                            tag="a"
                                                            :href="$sectionsSummary['schedule_url']"
                                                            size="sm"
                                                            color="gray"
                                                            icon="heroicon-m-arrow-right"
                                                            icon-position="after"
                                                        >
                                                            Schedule
                                                        </x-filament::button>
                                                    @elseif ($section['schedule_status'] === 'Published' && filled($section['timetable_url'] ?? null))
                                                        <x-filament::button
                                                            tag="a"
                                                            :href="$section['timetable_url']"
                                                            size="sm"
                                                            color="gray"
                                                            icon="heroicon-o-table-cells"
                                                        >
                                                            View timetable
                                                        </x-filament::button>
                                                    @endif
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </x-data-table>

                            @if ($yearRow['unassigned_students_count'] > 0)
                                <p class="tala-sections-unassigned-notice tabular-nums">
                                    <x-filament::icon icon="heroicon-m-information-circle" aria-hidden="true" />
                                    <span>
                                        + {{ number_format($yearRow['unassigned_students_count']) }} officially enrolled {{ \Illuminate\Support\Str::plural('student', $yearRow['unassigned_students_count']) }} without a section assignment.
                                    </span>
                                </p>
                            @endif
                        @endif
                    </div>
                </article>
            @endforeach
        </div>
    </div>
@endif
