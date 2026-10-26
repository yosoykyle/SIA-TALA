<div class="tala-meeting-inspector">
    <h3>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</h3>
    <div class="tala-meeting-when">
        <x-filament::icon icon="heroicon-o-calendar-days" />
        <div><p>{{ $meeting['dayLabel'] }}</p><strong>{{ $meeting['time'] }}</strong><span>{{ $meeting['duration'] }} duration</span></div>
    </div>
    <dl class="tala-meeting-essentials">
        <div>
            <x-filament::icon icon="heroicon-o-map-pin" />
            <div><dt>Location</dt><dd>{{ $meeting['room'] }}</dd></div>
        </div>
        @if ($showFaculty ?? false)
            <div>
                <x-filament::icon icon="heroicon-o-user" />
                <div><dt>Teacher</dt><dd>{{ $meeting['faculty'] }}</dd></div>
            </div>
        @endif
    </dl>
    <div class="tala-meeting-delivery">
        <x-filament::icon icon="heroicon-o-academic-cap" />
        <div><p>{{ $meeting['component'] }} · {{ $meeting['modality'] }}</p><span>{{ $meeting['status'] }}</span></div>
    </div>
    @if ($meeting['offGrid'])<p class="tala-meeting-notice"><x-filament::icon icon="heroicon-o-exclamation-triangle" />This meeting is outside the recorded teaching grid.</p>@endif
    <details class="tala-meeting-identifiers">
        <summary>Record identifiers</summary>
        <dl>
            <div><dt>Course code</dt><dd>{{ $meeting['courseCode'] }}</dd></div>
            <div><dt>Class code</dt><dd>{{ $meeting['class'] }}</dd></div>
        </dl>
    </details>
</div>
