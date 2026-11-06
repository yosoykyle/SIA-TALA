<h2 class="sr-only">Teaching assignment and notification delivery</h2>
<div class="tala-record-reference-grid">
    <section class="tala-record-reference-section" aria-label="Teaching assignment">
        <h3>Teaching assignment</h3>
        <p class="tala-record-source-person">{{ $roster->teachingAssignment?->faculty?->getFilamentName() ?? 'Faculty not recorded' }}</p>
    </section>
    <section class="tala-record-reference-section" aria-label="Assignment authority">
        <h3>Assignment authority</h3>
        <p>{{ $roster->teachingAssignment?->authority_reference ?? 'Not recorded' }}</p>
    </section>

    <section class="tala-record-reference-section tala-record-notifications" aria-label="Notification delivery">
        <header><div><h3>Notification delivery</h3><p>These notices report academic changes. Their delivery status does not change the released grades.</p></div>@if ($nestedSource ?? false) @if (($canRetryNotifications ?? false) && auth()->user()?->hasRole(\App\Models\User::StaffRoleRegistrar))<x-filament::button color="gray" wire:click="$parent.mountAction('resendAcademicNotice')">Retry notification</x-filament::button>@endif @else {{ $this->resendAcademicNoticeAction }} @endif</header>
        <ul class="tala-record-events">
            @forelse ($notifications->sortBy(fn ($notice) => match ($notice->status) { \App\Models\OperationalEvent::StatusFailed => 0, \App\Models\OperationalEvent::StatusPending => 1, default => 2 })->groupBy(fn ($notice) => $notice->event_type.'|'.$notice->status) as $notices)
                @php($notice = $notices->first())
                <li>
                    <strong>{{ str($notice->event_type)->replace('_', ' ')->ucfirst()->replace('Inc ', 'INC ') }}</strong>
                    <span class="tala-record-notice-state"><x-filament::badge :color="match ($notice->status) { \App\Models\OperationalEvent::StatusFailed => 'danger', \App\Models\OperationalEvent::StatusPending => 'warning', default => 'gray' }">{{ str($notice->status)->lower()->headline() }}</x-filament::badge><span>{{ $notices->count() }} {{ str('notice')->plural($notices->count()) }}</span></span>
                    <time datetime="{{ $notice->occurred_at?->toIso8601String() }}">{{ $notice->occurred_at?->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</time>
                </li>
            @empty
                <li><p>No academic notification has been recorded for this class.</p></li>
            @endforelse
        </ul>
        @if ($notifications->isNotEmpty())<p class="tala-record-event-caption">Grouped by notice and status · Latest recorded time · Asia/Manila</p>@endif
    </section>
</div>
