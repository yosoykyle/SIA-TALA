@props(['projection'])

<x-filament::section icon="heroicon-o-calendar-days">
    <x-slot name="heading">Examination Period</x-slot>
    <x-slot name="description">
        {{ $projection['term'] ?? 'Exact Term unavailable' }} · Source: Term Calendar Package{{ $projection['package_version'] ? ' v'.$projection['package_version'] : '' }}
    </x-slot>

    @if ($projection['status'] === 'Available')
        <p class="text-lg font-semibold">
            {{ $projection['opens_on']->format('M j, Y') }}–{{ $projection['closes_on']->format('M j, Y') }}
        </p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $projection['message'] }}</p>
        <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-3">
            <div><dt class="font-medium">Authority</dt><dd>{{ $projection['authority_reference'] ?: 'Not recorded' }}</dd></div>
            <div><dt class="font-medium">Owner</dt><dd>{{ $projection['owner'] }}</dd></div>
            <div><dt class="font-medium">As of</dt><dd>{{ $projection['as_of']->format('M j, Y g:i A') }} PHT</dd></div>
        </dl>
    @else
        <p class="font-medium">Examination Period unavailable</p>
        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $projection['message'] }}</p>
        <p class="mt-2 text-sm">Responsible: {{ $projection['owner'] }} · Safe next step: review the exact-Term Calendar Package.</p>
    @endif
</x-filament::section>
