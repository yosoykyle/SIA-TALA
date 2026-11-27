@props(['presentation', 'sourceKey', 'showModeToggle' => true])
<div
    x-data="{
        isMobile: window.matchMedia('(max-width: 639px)').matches,
        scheduleMode: {{ $showModeToggle
            ? "window.matchMedia('(min-width: 1024px)').matches ? 'weekly' : 'list'"
            : "window.matchMedia('(max-width: 639px)').matches ? 'list' : 'weekly'" }},
        init() {
            const mediaQuery = window.matchMedia('(max-width: 639px)');
            const syncViewport = (event) => {
                this.isMobile = event.matches;
                @if (! $showModeToggle)
                    this.scheduleMode = event.matches ? 'list' : 'weekly';
                @endif
            };
            if (typeof mediaQuery.addEventListener === 'function') {
                mediaQuery.addEventListener('change', syncViewport);
            } else if (typeof mediaQuery.addListener === 'function') {
                mediaQuery.addListener(syncViewport);
            }
        }
    }"
    class="enrollment-schedule" wire:key="learner-schedule-{{ $sourceKey }}"
>
    @if ($showModeToggle)
        <div class="enrollment-schedule-tools">
            <x-tala-navigation label="Schedule view" compact>
                <x-filament::tabs.item
                    alpine-active="scheduleMode === 'weekly'"
                    icon="heroicon-o-calendar-days"
                    x-bind:aria-current="scheduleMode === 'weekly' ? 'true' : false"
                    x-on:click="scheduleMode = 'weekly'"
                >
                    Weekly
                </x-filament::tabs.item>
                <x-filament::tabs.item
                    alpine-active="scheduleMode === 'list'"
                    icon="heroicon-o-list-bullet"
                    x-bind:aria-current="scheduleMode === 'list' ? 'true' : false"
                    x-on:click="scheduleMode = 'list'"
                >
                    List
                </x-filament::tabs.item>
            </x-tala-navigation>
        </div>
        <p class="sr-only" role="status" x-text="scheduleMode === 'weekly' ? 'Showing weekly schedule' : 'Showing meeting list'"></p>
    @else
        <div class="enrollment-schedule-tools sm:hidden" x-cloak x-show="isMobile">
            <x-tala-navigation label="Mobile layout" stretch>
                <x-filament::tabs.item
                    alpine-active="scheduleMode === 'list'"
                    icon="heroicon-o-list-bullet"
                    x-bind:aria-current="scheduleMode === 'list' ? 'true' : false"
                    x-on:click="scheduleMode = 'list'"
                >
                    Agenda
                </x-filament::tabs.item>
                <x-filament::tabs.item
                    alpine-active="scheduleMode === 'weekly'"
                    icon="heroicon-o-calendar-days"
                    x-bind:aria-current="scheduleMode === 'weekly' ? 'true' : false"
                    x-on:click="scheduleMode = 'weekly'"
                >
                    Grid
                </x-filament::tabs.item>
            </x-tala-navigation>
        </div>
    @endif
    <div x-show="scheduleMode === 'weekly'" class="enrollment-schedule-week">
        <h3 class="sr-only">Weekly schedule</h3>
        <x-tala-timetable :presentation="$presentation" focused controlled embedded :show-window="false" />
    </div>
    <div x-cloak x-show="scheduleMode === 'list'" class="enrollment-agenda">
        @foreach (collect($presentation['meetings'])->groupBy('dayLabel') as $day => $meetings)
            <section>
                <h3>{{ $day ?: 'Day unavailable' }}</h3>
                <ul>
                    @foreach ($meetings as $meeting)
                        <li>
                            <p class="enrollment-agenda-time tabular-nums">{{ $meeting['time'] ?: 'Time unavailable' }}</p>
                            <div>
                                <h4>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</h4>
                                <p>{{ $meeting['courseCode'] }} · {{ $meeting['class'] }}</p>
                                <p>{{ $meeting['room'] }} · {{ $meeting['faculty'] }}</p>
                                @if ($meeting['component'] !== 'Meeting' || $meeting['modality'] !== 'Face to face')<p>{{ $meeting['component'] }} · {{ $meeting['modality'] }}</p>@endif
                                @if (! $meeting['plottable'])<p>Meeting time is unavailable or invalid. Contact the Registrar.</p>@endif
                                @if ($meeting['offGrid'])<p>Outside the published teaching window. Contact the Registrar.</p>@endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</div>
