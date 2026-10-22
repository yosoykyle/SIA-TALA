<div class="accounting-proof-context">
    <strong>{{ $review['case']->credentialUser?->getFilamentName() }}</strong>
    @if ($review['event'])
        <p>{{ $review['kind'] === 'timetable' ? 'Schedule change' : 'Academic source changed' }}</p>
        <p>{{ $review['event']->reason }}</p>
        @if ($review['revision'])
            @foreach ($review['revision']->changes_snapshot as $key => $change)
                @php($meeting = $review['revision']->sourceVersion?->meetings->firstWhere('id', $change['meeting_id'] ?? $key))
                @if ($meeting)
                    <p>{{ $review['proposal']?->items->firstWhere('section_id', $meeting->section_id)?->course_title_snapshot ?? $meeting->section?->code }}</p>
                    <p>{{ substr($meeting->starts_at, 0, 5) }}-{{ substr($meeting->ends_at, 0, 5) }} &rarr; {{ ($change['remove'] ?? false) ? 'Meeting removed' : substr($change['starts_at'] ?? $meeting->starts_at, 0, 5).'-'.substr($change['ends_at'] ?? $meeting->ends_at, 0, 5) }}</p>
                @endif
            @endforeach
            <p>Draft timetable revision. The published schedule stays unchanged until publication.</p>
        @endif
        <p>Recorded by {{ $review['event']->actor?->getFilamentName() ?? 'Source owner' }} &middot; {{ $review['event']->recorded_at?->format('M j, Y g:i A') }}</p>
    @elseif ($review['load'] === [])
        <p>The current curriculum load is unavailable or changed. Prepare a successor proposal before recording approval.</p>
    @else
        <p>Graduation candidate &middot; Proposed subjects</p>
        <p>{{ $review['load']['year_level'] ?? 'Unavailable' }} &middot; {{ $review['load']['term_label'] ?? 'Unavailable' }}</p>
        <dl class="registrar-overload-load">
            <div><dt>Curriculum load</dt><dd>{{ (float) $review['load']['normal_total'] }} <span>units</span></dd></div>
            <div><dt>Proposed load</dt><dd>{{ (float) $review['load']['requested_total'] }} <span>units</span></dd></div>
        </dl>
    @endif
</div>
