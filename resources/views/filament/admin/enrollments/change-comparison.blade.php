<div class="registrar-adjustment-comparison">
    @foreach (['before' => 'Current official class', 'after' => 'Proposed class'] as $side => $label)
        @php($class = $change[$side])
        <div>
            <h3>{{ $label }}</h3>
            @if ($class)
                <p>{{ $class->section?->code ?? 'Class unavailable' }}</p>
                @foreach (($side === 'before' ? $class->proposalItem?->meeting_snapshot : $class->meeting_snapshot) ?? [] as $meeting)
                    <p>{{ [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'][$meeting['day_of_week']] ?? 'Day unavailable' }} &middot; {{ substr($meeting['starts_at'], 0, 5) }}&ndash;{{ substr($meeting['ends_at'], 0, 5) }}</p>
                @endforeach
            @else
                <p>No current class</p>
            @endif
        </div>
    @endforeach
</div>
