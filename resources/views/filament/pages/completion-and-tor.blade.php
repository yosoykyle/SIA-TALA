<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Completion authority and TOR handoff</x-slot>
            <x-slot name="description">Readiness is derived from released academics and named source-owned blockers. Conferral and TOR history are append-only.</x-slot>
            <x-filament::button :href="$torRequestsUrl" tag="a" color="gray" icon="heroicon-m-document-text">Open TOR requests and history</x-filament::button>
        </x-filament::section>

        @forelse ($completionRows as $row)
            <x-filament::section>
                <x-slot name="heading">{{ $row['student']->student_number }} · {{ collect([$row['student']->last_name, $row['student']->first_name])->filter()->implode(', ') }}</x-slot>
                <x-slot name="description">{{ $row['student']->program?->name ?? 'Program unavailable' }}</x-slot>
                <div class="grid gap-4 md:grid-cols-3">
                    <div><p class="text-xs font-medium uppercase text-gray-500">Readiness</p><p class="font-semibold">{{ str($row['state'])->headline() }}</p></div>
                    <div><p class="text-xs font-medium uppercase text-gray-500">Application</p><p>{{ $row['application'] ? 'Active v'.$row['application']->version : 'No active application' }}</p></div>
                    <div><p class="text-xs font-medium uppercase text-gray-500">Conferral</p><p>{{ $row['conferral'] ? $row['conferral']->degree_name.' · '.$row['conferral']->conferred_on->format('M j, Y') : 'Not recorded' }}</p></div>
                </div>
                @if ($row['blockers'] !== [])
                    <div class="mt-4 space-y-2" aria-label="Completion blockers">
                        @foreach ($row['blockers'] as $blocker)
                            <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 dark:border-warning-700 dark:bg-warning-950">
                                <p class="font-medium">{{ $blocker['reason'] }}</p>
                                <p class="text-sm">Owner: {{ $blocker['owner'] }} · Recovery: {{ $blocker['recovery'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @empty
            <x-filament::section>
                <x-slot name="heading">No completion applications yet</x-slot>
                <p>Students appear after submitting a graduation application from Student Academics.</p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
