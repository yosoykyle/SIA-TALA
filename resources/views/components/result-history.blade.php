@props(['events', 'fallbackResult' => null, 'fallbackVersion' => null])
<x-record-history heading="Result history" :paginator="$events instanceof \Illuminate\Pagination\LengthAwarePaginator ? $events : null" method="gotoResultHistoryPage" class="tala-record-history">
        @forelse ($events as $event)
            <x-record-history-item :heading="$event->result_code.' · '.str($event->event_type)->lower()->replace('_', ' ')->ucfirst()" :date="$event->released_at">
                <p>{{ $event->recorder?->getFilamentName() ?? 'Recorded actor unavailable' }}</p>
                @if (filled($event->reason))<p><strong>Reason:</strong> {{ $event->reason }}</p>@endif
            </x-record-history-item>
        @empty
            <x-record-history-item :heading="($fallbackResult ?? '—').' · Release history unavailable'"><p>The current result is recorded, but its earlier release event is not available.</p>@if ($fallbackVersion)<p>Class version {{ $fallbackVersion }}</p>@endif</x-record-history-item>
        @endforelse
</x-record-history>
