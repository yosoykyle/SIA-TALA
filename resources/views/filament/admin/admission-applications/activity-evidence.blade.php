@php
    $format = fn ($moment): ?string => $moment?->timezone($timezone)->format('M j, Y · g:i A');
    $submission = $application->currentSubmissionVersion;
@endphp
<div class="tala-activity-evidence">
    <dl class="tala-activity-facts">
        <div>
            <dt>Submitted at</dt>
            <dd>{{ $format($application->submitted_at) ?? 'Not submitted' }}</dd>
        </div>
        <div>
            <dt>Last record update</dt>
            <dd>{{ $format($application->updated_at) }}</dd>
        </div>
        <div>
            <dt>Current submission</dt>
            <dd>{{ $submission ? 'Submission #'.$submission->version : 'None' }}</dd>
        </div>
        <div>
            <dt>Requirement Set version</dt>
            <dd>{{ $submission?->requirementSet ? 'Version '.$submission->requirementSet->version : 'None' }}</dd>
        </div>
        <div>
            <dt>Internal application ID</dt>
            <dd class="tala-activity-mono">{{ $application->id }}</dd>
        </div>
    </dl>

    <h3 class="tala-activity-heading">Record events <span>{{ $events->count() }}</span></h3>

    @if ($events->isEmpty())
        <p class="tala-activity-empty">No recorded event yet.</p>
    @else
        <ol class="tala-activity-events" aria-label="Record events, newest first">
            @foreach ($events as $event)
                <li @class(['is-latest' => $loop->first])>
                    <div>
                        <p class="tala-activity-event">{{ str($event->event_type)->headline() }}</p>
                        <p class="tala-activity-actor">{{ $event->actor?->getFilamentName() ?? 'System' }}</p>
                    </div>
                    <time datetime="{{ $event->occurred_at?->toIso8601String() }}">{{ $format($event->occurred_at) }}</time>
                </li>
            @endforeach
        </ol>
        <p class="tala-activity-footnote">Newest first.</p>
    @endif
</div>
