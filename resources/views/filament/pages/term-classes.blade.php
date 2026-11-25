<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-work {{ $classesView === 'cohorts' ? 'tala-term-cohorts' : '' }}" aria-label="Classes and student sections">
            <div class="tala-work-tabs tala-work-tabs--full">
                <x-tala-navigation label="Classes view" compact stretch class="tala-work-tabs__nav">
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id])"
                        :active="$classesView === 'classes'"
                        icon="heroicon-o-squares-2x2"
                        wire:click.prevent="chooseClassesView('classes')"
                        wire:loading.attr="disabled"
                        wire:target="chooseClassesView"
                    >
                        Classes
                    </x-filament::tabs.item>
                    <x-filament::tabs.item
                        tag="a"
                        :href="\App\Filament\Pages\TermClasses::getUrl(['termId' => $term->id, 'classesView' => 'cohorts'])"
                        :active="$classesView === 'cohorts'"
                        icon="heroicon-o-user-group"
                        wire:click.prevent="chooseClassesView('cohorts')"
                        wire:loading.attr="disabled"
                        wire:target="chooseClassesView"
                    >
                        Student sections
                    </x-filament::tabs.item>
                </x-tala-navigation>
            </div>
            <section class="tala-task-surface tala-resource-readiness-bar" aria-labelledby="term-classes-scope-heading">
                <div class="tala-resource-readiness-bar__row">
                    <div class="tala-resource-readiness-bar__main">
                        <div class="tala-resource-readiness-bar__headline">
                            <h2 id="term-classes-scope-heading" class="tala-resource-readiness-bar__heading">
                                Showing all programs for {{ $term->label }}
                            </h2>
                        </div>
                        <p class="tala-resource-readiness-bar__meta">
                            All confirmed classes across every program are scheduled together so shared rooms and teachers never conflict. Filter by program or year below.
                        </p>
                    </div>
                </div>
            </section>
            @if ($this->isTermClosed())
                <x-tala-access-alert variant="neutral" icon="heroicon-o-lock-closed" title="Closed term · Read-only">
                    <p>This term is closed. Classes and student sections are preserved for historical reference and cannot be added or edited.</p>
                </x-tala-access-alert>
            @elseif ($this->hasPublishedTimetable())
                <x-tala-access-alert variant="info" icon="heroicon-o-lock-closed" title="Official timetable is published">
                    <p>
                        Pre-publish class and student section edits are locked while the official timetable is live.
                        Use <a class="fi-link" href="{{ \App\Filament\Pages\TermTimetable::getUrl(['termId' => $term->id, 'timetableState' => 'official']) }}">Timetable &rarr; Revise official schedule</a> for controlled schedule changes.
                    </p>
                </x-tala-access-alert>
            @elseif ($classesView === 'classes' && ! $activePackage)
                <div class="tala-task-finding">
                    <div>
                        <strong>Teaching calendar not active</strong>
                        <p>{{ $readOnly ? 'The Registrar must activate the teaching calendar before loading and confirming classes.' : 'Loading and confirming classes requires an active teaching calendar. Student sections can be added now.' }}</p>
                    </div>
                    <x-filament::button tag="a" color="gray" size="sm" icon="heroicon-o-calendar" :href="\App\Filament\Pages\TermCalendar::getUrl(['termId' => $term->id])">Review calendar</x-filament::button>
                </div>
            @endif
            @if (! empty($capacityHeadroom))
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
                    <section class="tala-task-surface tala-capacity-headroom-slim" aria-label="Capacity headroom summary">
                        <span class="tabular-nums">
                            All programs · {{ $seats['active_rooms'] }} active {{ \Illuminate\Support\Str::plural('room', $seats['active_rooms']) }}@if ($seats['active_rooms'] > 0) (up to {{ $seats['largest_room'] }} seats)@endif · 0 classes added yet
                        </span>
                        <x-filament::button
                            tag="a"
                            color="gray"
                            size="sm"
                            icon="heroicon-m-arrow-right"
                            icon-position="after"
                            :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id])"
                        >
                            Faculty &amp; rooms
                        </x-filament::button>
                    </section>
                @else
                    <section class="tala-capacity-headroom is-{{ $capacityHeadroom['status'] }}" aria-labelledby="capacity-headroom-heading">
                        <header class="tala-capacity-headroom__header">
                            <div class="tala-capacity-headroom__title">
                                <x-filament::icon icon="heroicon-o-chart-bar" class="tala-capacity-headroom__icon" aria-hidden="true" />
                                <h2 id="capacity-headroom-heading">Capacity headroom</h2>
                                <x-filament::badge :color="$headroomColor" size="sm">{{ $capacityHeadroom['status_label'] }}</x-filament::badge>
                            </div>
                            <x-filament::button
                                tag="a"
                                color="gray"
                                size="sm"
                                icon="heroicon-m-arrow-right"
                                icon-position="after"
                                :href="\App\Filament\Pages\TermResources::getUrl(['termId' => $term->id])"
                            >
                                Faculty &amp; rooms
                            </x-filament::button>
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
            @endif
            {{ $this->table }}
        </section>
    @else
        <section class="tala-task-surface" aria-label="Classes and student sections">
            <x-servitech-empty-state
                :illustrated="false"
                icon="heroicon-o-rectangle-group"
                heading="Choose a term"
                description="Choose a term above to manage its classes and student sections."
            />
        </section>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
