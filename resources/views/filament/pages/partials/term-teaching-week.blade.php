<section class="tala-calendar-week" aria-labelledby="teaching-week-heading">
    <h3 id="teaching-week-heading">Weekly teaching hours</h3>
    @forelse (collect($teachingWeekRows)->groupBy(fn (array $row): string => json_encode($row['segments'])) as $days)
        @php
            $day = $days->first();
            $dayNames = $days->pluck('day')->all();
            $dayLabel = match ($dayNames) {
                ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] => 'Monday to Friday',
                ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'] => 'Monday to Saturday',
                default => implode(', ', $dayNames),
            };
        @endphp
        <div class="tala-calendar-week-group">
            <h4>{{ $dayLabel }}</h4>
            <div class="tala-calendar-hours-chart" role="img" aria-label="{{ $dayLabel }} teaching hours: {{ $day['summary'] }}">
                @foreach ($day['segments'] as $segment)
                    <span class="tala-calendar-hours-band {{ $segment['type'] === 'break' ? 'is-break' : '' }}" style="left: {{ $segment['left'] }}%; width: {{ $segment['width'] }}%;" title="{{ $segment['label'] }}: {{ $segment['time'] }}"></span>
                @endforeach
            </div>
            <dl class="tala-calendar-hours-key">
                @foreach ($day['segments'] as $segment)
                    <div class="{{ $segment['type'] === 'break' ? 'is-break' : '' }}"><dt><span aria-hidden="true"></span>{{ $segment['label'] }}</dt><dd>{{ $segment['time'] }}</dd></div>
                @endforeach
            </dl>
        </div>
    @empty
        <p class="tala-task-finding" role="status">No teaching days are recorded for this calendar.</p>
    @endforelse
</section>
