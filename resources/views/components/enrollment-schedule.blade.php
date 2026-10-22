@props(['presentation', 'sourceKey'])
<div
    x-data="{ scheduleMode: window.matchMedia('(min-width: 1024px)').matches ? 'weekly' : 'list' }"
    x-on:resize.window.debounce.150ms="scheduleMode = window.matchMedia('(min-width: 1024px)').matches ? 'weekly' : 'list'"
    class="enrollment-schedule" wire:key="learner-schedule-{{ $sourceKey }}"
>
    <div class="enrollment-schedule-tools">
        <p>Times in Asia/Manila</p>
        <x-filament::button type="button" color="gray" x-on:click="scheduleMode = scheduleMode === 'weekly' ? 'list' : 'weekly'">
            <span x-text="scheduleMode === 'weekly' ? 'View meeting list' : 'View week'">View meeting list</span>
        </x-filament::button>
    </div>
    <p class="sr-only" role="status" x-text="scheduleMode === 'weekly' ? 'Showing weekly schedule' : 'Showing meeting list'"></p>
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
                            <p class="enrollment-agenda-time">{{ $meeting['time'] ?: 'Time unavailable' }}</p>
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
