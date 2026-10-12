@props(['presentation', 'focused' => false, 'controlled' => false, 'embedded' => false, 'nativeDetails' => false])
@php($overviewId = 'timetable-overview-'.substr(hash('sha256', $presentation['source']), 0, 16))

<section
    {{ $attributes->class(['tala-timetable', 'tala-timetable-focused' => $focused]) }}
    x-data="{
        mode: 'weekly', selected: null,
        meetings: {{ \Illuminate\Support\Js::from($presentation['meetings']) }},
        filters: {faculty: '', room: '', class: '', modality: ''},
        matches(meeting) { return Object.entries(this.filters).every(([key, value]) => !value || meeting[key] === value) },
        get visibleCount() { return this.meetings.filter(meeting => this.matches(meeting)).length },
        get selectedMeeting() { return this.meetings.find(meeting => meeting.id === this.selected) ?? null }
    }"
>
    @if (! $embedded)<div class="tala-timetable-heading">
        <div>
            <h3>{{ $presentation['title'] }}</h3>
            <p>{{ $presentation['source'] }}</p>
        </div>
        @if (! $controlled)
        <div class="tala-timetable-controls" aria-label="Timetable view">
            <x-tala-navigation label="Timetable view"><x-filament::tabs.item x-on:click="mode = 'weekly'" x-bind:class="{'fi-active': mode === 'weekly'}" x-bind:aria-pressed="mode === 'weekly'">Weekly overview</x-filament::tabs.item><x-filament::tabs.item x-on:click="mode = 'list'" x-bind:class="{'fi-active': mode === 'list'}" x-bind:aria-pressed="mode === 'list'">Meeting list</x-filament::tabs.item></x-tala-navigation>
            @if (! $focused)
                <x-filament::button color="gray" x-on:click="$dispatch('open-modal', {id: '{{ $overviewId }}'})">Whole timetable</x-filament::button>
            @endif
        </div>
        @endif
    </div>
    @endif

    @if (($presentation['synthetic'] ?? false) && ! $embedded)<p class="tala-timetable-guide">Synthetic review data</p>@endif
    @if ($presentation['meetings'] === [])
        <p class="tala-timetable-empty">No meetings are recorded for this version. Return to the timetable workflow to prepare or select a timetable.</p>
    @else
        @if (! $controlled)
        <details class="tala-timetable-filters">
            <summary>Filter by faculty, room, class or modality</summary>
            <div class="tala-timetable-filter-fields">
                @foreach (['faculty' => 'Faculty', 'room' => 'Room', 'class' => 'Class', 'modality' => 'Modality'] as $field => $label)
                    <label>
                        <span>{{ $label }}</span>
                        <select x-model="filters.{{ $field }}" x-on:change="selected = null">
                            <option value="">All {{ strtolower($label) }}</option>
                            @foreach ($presentation['filters'][$field] as $option)
                                <option value="{{ $option }}">{{ $option }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
                <x-filament::button color="gray" x-on:click="filters = {faculty: '', room: '', class: '', modality: ''}; selected = null">Clear filters</x-filament::button>
            </div>
        </details>
        @endif
        @if (! $embedded)<p class="tala-timetable-guide">
            <span aria-live="polite"><span x-text="visibleCount">{{ count($presentation['meetings']) }}</span> of {{ count($presentation['meetings']) }} meetings shown.</span>
            Select a meeting to see its details. Times are in Asia/Manila.
            @if (! $presentation['snapshotAvailable'])
                Recorded calendar geometry is unavailable; the overview uses recorded meeting days and times.
            @endif
        </p>
        @endif
        <p x-cloak x-show="visibleCount === 0" class="tala-timetable-empty">No meetings match these filters. Clear the filters to see the full timetable.</p>

        @if (! $nativeDetails)<details x-cloak x-show="selectedMeeting" x-bind:open="selected !== null" class="tala-timetable-selection">
            <summary x-ref="selectionSummary">Selected meeting: <span x-text="selectedMeeting?.courseCode"></span> · <span x-text="selectedMeeting?.class"></span></summary>
            <p><strong x-text="selectedMeeting?.courseTitle"></strong></p>
            <dl>
                <div><dt>When</dt><dd><span x-text="selectedMeeting?.dayLabel"></span> <span x-text="selectedMeeting?.time"></span></dd></div>
                <div><dt>Duration</dt><dd x-text="selectedMeeting?.duration"></dd></div><div><dt>Faculty</dt><dd x-text="selectedMeeting?.faculty"></dd></div>
                <div><dt>Location</dt><dd x-text="selectedMeeting?.room"></dd></div>
                <div><dt>Delivery</dt><dd><span x-text="selectedMeeting?.component"></span> · <span x-text="selectedMeeting?.modality"></span></dd></div>
                <div><dt>Record status</dt><dd x-text="selectedMeeting?.status"></dd></div>
            </dl>
            <p x-show="selectedMeeting?.offGrid">This meeting is outside the recorded teaching grid. Review it before relying on this timetable.</p>
            <x-filament::button color="gray" x-on:click="selected = null">Close meeting details</x-filament::button>
        </details>
        @endif

        @if ($presentation['slots'] > 0 && $presentation['days'] !== [])
            <p x-show="mode === 'weekly'" class="tala-timetable-window">Recorded teaching window · {{ $presentation['ticks'][0]['label'] }}–{{ $presentation['ticks'][count($presentation['ticks']) - 1]['label'] }} · Asia/Manila</p>
            <div x-show="mode === 'weekly'" class="tala-timetable-weekly" tabindex="0" role="region" aria-label="Weekly calendar, full recorded teaching window. Scroll to later hours." style="--timetable-days: {{ count($presentation['days']) }}; --timetable-slots: {{ $presentation['slots'] }};">
                <div class="tala-timetable-axis" aria-hidden="true">
                    <div class="tala-timetable-day-heading">Time</div>
                    <div class="tala-timetable-axis-body">
                        @foreach ($presentation['ticks'] as $tick)
                            <span @class(['tala-timetable-hour-label' => str_contains($tick['label'], ':00 '), 'tala-timetable-first-label' => $loop->first, 'tala-timetable-last-label' => $loop->last]) style="top: {{ $tick['offset'] }}%">{{ $tick['label'] }}</span>
                        @endforeach
                    </div>
                </div>
                @foreach ($presentation['days'] as $day)
                    <section class="tala-timetable-day" aria-label="{{ $day['label'] }} meetings">
                        <h4 class="tala-timetable-day-heading">{{ $day['label'] }}</h4>
                        <div class="tala-timetable-day-body">
                            @foreach ($presentation['ticks'] as $tick)
                                <span @class(['tala-timetable-rule', 'tala-timetable-hour-rule' => str_contains($tick['label'], ':00 ')]) aria-hidden="true" style="top: {{ $tick['offset'] }}%"></span>
                            @endforeach
                            @foreach ($presentation['blockedPeriods'] as $period)
                                @if ($period['day'] === $day['number'])
                                    <span class="tala-timetable-break" style="top: {{ $period['top'] }}%; height: {{ $period['height'] }}%;" title="{{ $period['label'] }} · {{ $period['time'] }}">{{ $period['label'] }}<br>{{ $period['time'] }}</span>
                                @endif
                            @endforeach
                            @foreach ($presentation['meetings'] as $meeting)
                                @if ($meeting['plottable'] && $meeting['day'] === $day['number'])
                                    <button
                                        type="button"
                                        class="tala-timetable-meeting {{ $meeting['component'] === 'Laboratory' ? 'tala-timetable-laboratory' : '' }} {{ $meeting['end'] - $meeting['start'] < 60 ? 'tala-timetable-compact-meeting' : '' }}"
                                        style="top: {{ $meeting['top'] }}%; height: {{ $meeting['height'] }}%; inset-inline-start: {{ $meeting['lane'] / $meeting['lanes'] * 100 }}%; width: {{ 100 / $meeting['lanes'] }}%;"
                                        x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))"
                                        @if ($nativeDetails) wire:click="mountAction('viewMeeting', {}, { table: true, recordKey: '{{ $meeting['id'] }}' })"
                                        @else x-on:click="selected = {{ \Illuminate\Support\Js::from($meeting['id']) }}; $nextTick(() => $refs.selectionSummary.focus())" @endif
                                        @if (! $nativeDetails) x-bind:aria-pressed="selected === {{ \Illuminate\Support\Js::from($meeting['id']) }}" @endif
                                        aria-label="{{ $meeting['courseCode'] }}, {{ $meeting['courseTitle'] }}, {{ $meeting['class'] }}, {{ $day['label'] }} {{ $meeting['time'] }}, {{ $meeting['faculty'] }}, {{ $meeting['room'] }}, {{ $meeting['component'] }}, {{ $meeting['modality'] }}{{ $meeting['offGrid'] ? ', outside recorded teaching grid' : '' }}"
                                    >
                                        <strong class="tala-timetable-course-title">{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong>
                                        <span class="tala-timetable-meeting-time">{{ $meeting['time'] }}</span>
                                        <span class="tala-timetable-meeting-room">{{ $meeting['room'] }}</span>
                                        @if (! $nativeDetails)<span class="tala-timetable-meeting-class">{{ $meeting['class'] }} · {{ $meeting['modality'] }}</span>@endif
                                        @if ($meeting['offGrid'])<span class="tala-timetable-attention">Outside teaching grid</span>@endif
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </section>
                @endforeach
            </div>
        @endif

        <div class="tala-timetable-list" :class="{'tala-timetable-list-active': mode === 'list'}">
            @foreach ($presentation['meetings'] as $meeting)
                @if ($nativeDetails)
                    <div class="tala-timetable-list-meeting" x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))">
                        <button type="button" class="tala-timetable-mobile-meeting" wire:click="mountAction('viewMeeting', {}, { table: true, recordKey: '{{ $meeting['id'] }}' })">
                            <span class="tala-timetable-list-time">{{ $meeting['dayLabel'] }}<br>{{ $meeting['time'] }}</span>
                            <span><strong>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong></span>
                            <span class="tala-timetable-meeting-room">{{ $meeting['room'] }}<span class="tala-timetable-list-title">View details</span></span>
                        </button>
                    </div>
                @else
                <details
                    class="tala-timetable-list-meeting {{ ! $meeting['plottable'] ? 'tala-timetable-unplaced' : '' }}"
                    x-show="matches(meetings.find(meeting => meeting.id === {{ \Illuminate\Support\Js::from($meeting['id']) }}))"
                >
                    <summary>
                        <span class="tala-timetable-list-time">{{ $meeting['dayLabel'] }}<br>{{ $meeting['time'] }}</span>
                        <span><strong>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</strong><span class="tala-timetable-list-title">{{ $meeting['courseCode'] }} · {{ $meeting['class'] }}</span></span>
                        <span class="tala-timetable-meeting-room">{{ $meeting['room'] }} · {{ $meeting['component'] }}</span>
                    </summary>
                    <dl>
                        <div><dt>Duration</dt><dd>{{ $meeting['duration'] }}</dd></div><div><dt>Faculty</dt><dd>{{ $meeting['faculty'] }}</dd></div>
                        <div><dt>Modality</dt><dd>{{ $meeting['modality'] }}</dd></div>
                        <div><dt>Record status</dt><dd>{{ $meeting['status'] }}</dd></div>
                    </dl>
                    @if (! $meeting['plottable'])<p>This meeting has an invalid or missing day or time. Review its source record; it cannot be placed on the weekly grid.</p>@endif
                    @if ($meeting['offGrid'])<p>This meeting is outside the recorded teaching grid.</p>@endif
                </details>
                @endif
            @endforeach
        </div>
        @if (collect($presentation['meetings'])->contains('plottable', false))
            <p class="tala-timetable-guide">Some meetings have an invalid or missing day or time. They remain visible in the meeting list.</p>
        @endif
    @endif
</section>

@if (! $focused)
    <x-filament::modal :id="$overviewId" width="screen" sticky-header heading="Whole timetable">
        <x-tala-timetable :presentation="$presentation" :focused="true" />
    </x-filament::modal>
@endif
