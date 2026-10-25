@if (isset($historyTerm))
    <div class="tala-term-historical-grid">
        <dl class="tala-term-historical-range">
            <dt>Historical day range</dt>
            <dd><span><small>Starts</small>{{ $historyTerm->scheduling_day_starts_at ? \Carbon\CarbonImmutable::parse($historyTerm->scheduling_day_starts_at)->format('g:i A') : 'Not recorded' }}</span><x-filament::icon icon="heroicon-o-arrow-long-right" /><span><small>Ends</small>{{ $historyTerm->scheduling_day_ends_at ? \Carbon\CarbonImmutable::parse($historyTerm->scheduling_day_ends_at)->format('g:i A') : 'Not recorded' }}</span></dd>
        </dl>
        <dl class="tala-term-history-facts">
            <div><dt>Historical slot duration</dt><dd>{{ $historyTerm->scheduling_slot_minutes !== null ? $historyTerm->scheduling_slot_minutes.' minutes' : 'Not recorded' }}</dd></div>
            <div><dt>Historical grid days</dt><dd>{{ collect($historyTerm->scheduling_days ?? [])->map(fn ($day) => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'][(int) $day] ?? 'Unknown day')->implode(', ') ?: 'Approved grid is recorded in Term Planning' }}</dd></div>
        </dl>
    </div>
@else
    <ol class="tala-term-calendar-history" aria-label="Recorded calendar versions">
        @forelse ($calendarVersions as $version)
            <li>
                <header><h3>Version {{ $version->version }}</h3><x-filament::badge :color="$version->state === \App\Models\TermCalendarPackage::StateActive ? 'success' : 'gray'">{{ $version->state }}</x-filament::badge></header>
                <p class="tala-term-version-period"><span class="sr-only">Classes start </span><time datetime="{{ $version->classes_start_on?->toDateString() }}">{{ $version->classes_start_on?->format('M j, Y') ?? 'Start date missing' }}</time><x-filament::icon icon="heroicon-o-arrow-long-right" /><span class="sr-only">Classes end </span><time datetime="{{ $version->classes_end_on?->toDateString() }}">{{ $version->classes_end_on?->format('M j, Y') ?? 'End date missing' }}</time></p>
                <dl class="tala-term-history-facts">
                    <div><dt>Approval reference</dt><dd>{{ $version->authority_reference ?: 'Not recorded' }}</dd></div>
                    <div><dt>Recorded by</dt><dd>{{ $version->recorder?->name ?? 'Recorder unavailable' }}<small>{{ \App\Support\DisplayDateTime::format($version->created_at) }} · Asia/Manila</small></dd></div>
                </dl>
            </li>
        @empty
            <li class="tala-work-footnote">No calendar versions recorded.</li>
        @endforelse
    </ol>
@endif
