@php
    $courseCode = trim((string) ($meeting['courseCode'] ?? ''));
    $classLabel = trim((string) ($meeting['class'] ?? ''));
    $hasClassLabel = $classLabel !== '' && $classLabel !== 'Class unavailable';
    $subtitle = match (true) {
        $hasClassLabel && $courseCode !== '' && str_starts_with($classLabel, $courseCode) => $classLabel,
        default => collect([$courseCode !== '' ? $courseCode : null, $hasClassLabel ? $classLabel : null])->filter()->implode(' · '),
    };
    $showStatus = filled($meeting['status'] ?? null) && $meeting['status'] !== 'Published';
@endphp
<div class="tala-meeting-inspector">
    <div class="tala-meeting-header">
        <h3>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</h3>
        @if ($subtitle !== '')
            <p class="tala-meeting-subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    <div class="tala-meeting-when">
        <x-filament::icon icon="heroicon-o-calendar-days" aria-hidden="true" />
        <div>
            <p>{{ $meeting['dayLabel'] }}</p>
            <strong>{{ $meeting['time'] }}</strong>
            <span>{{ $meeting['duration'] }} duration</span>
        </div>
    </div>
    <dl class="tala-meeting-essentials">
        <div>
            <x-filament::icon icon="heroicon-o-map-pin" aria-hidden="true" />
            <div><dt>Location</dt><dd>{{ $meeting['room'] }}</dd></div>
        </div>
        @if ($showFaculty ?? false)
            <div>
                <x-filament::icon icon="heroicon-o-user" aria-hidden="true" />
                <div><dt>Teacher</dt><dd>{{ $meeting['faculty'] }}</dd></div>
            </div>
        @endif
    </dl>
    <div class="tala-meeting-delivery">
        <x-filament::icon icon="heroicon-o-academic-cap" aria-hidden="true" />
        <div>
            <p>{{ $meeting['component'] }} · {{ $meeting['modality'] }}</p>
            @if ($showStatus)
                <span>{{ $meeting['status'] }}</span>
            @endif
        </div>
    </div>
    @if ($meeting['offGrid'])
        <p class="tala-meeting-notice">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" aria-hidden="true" />
            This meeting is outside the recorded teaching grid.
        </p>
    @endif
</div>
