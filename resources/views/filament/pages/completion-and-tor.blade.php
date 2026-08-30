<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Completion authority and TOR handoff</x-slot>
            <x-slot name="description">Readiness is derived from released academics and named source-owned blockers. Conferral and TOR history are append-only.</x-slot>
            <x-filament::button :href="$torRequestsUrl" tag="a" color="gray" icon="heroicon-m-document-text">Open TOR requests and history</x-filament::button>
        </x-filament::section>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
