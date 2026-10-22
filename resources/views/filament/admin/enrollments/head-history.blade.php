@if ($case->registrationEvents->isNotEmpty())
    <ol class="head-enrollment-history">@foreach ($case->registrationEvents->sortByDesc('sequence') as $event)
        <li><div><strong>{{ \Illuminate\Support\Str::headline($event->event_type) }}</strong><p>{{ $event->reason }}</p>@if ($event->authority_reference)<p>Authority: {{ $event->authority_reference }}</p>@endif</div><p>{{ $event->actor?->getFilamentName() ?? 'Actor unavailable' }}<span>{{ $event->recorded_at?->timezone('Asia/Manila')->format('j M Y, g:i A') ?? 'Time unavailable' }}</span></p></li>
    @endforeach</ol>
@else<p class="head-enrollment-note">No case activity is recorded.</p>@endif
