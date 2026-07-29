<x-filament-panels::page>
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Academic decisions requiring oversight</x-slot>
            <x-slot name="description">
                Open the relevant evidence and act only where your assigned policy permits. Registrar and Faculty
                records keep their existing owners; this page does not silently approve or transfer work.
            </x-slot>
        </x-filament::section>

        @forelse ($this->approvalAreas() as $area)
            <x-filament::section :icon="$area['icon']">
                <x-slot name="heading">{{ $area['title'] }}</x-slot>
                <x-slot name="description">{{ $area['description'] }}</x-slot>

                <div class="pt-2">
                    <x-filament::button
                        :href="$area['url']"
                        tag="a"
                        icon="heroicon-m-arrow-right"
                        icon-position="after"
                    >
                        {{ $area['action'] }}
                    </x-filament::button>
                </div>
            </x-filament::section>
        @empty
            <x-filament::section icon="heroicon-o-check-circle">
                <x-slot name="heading">No approval queues are assigned</x-slot>
                <x-slot name="description">
                    Your account does not currently have access to a scoped academic approval queue.
                    Use Academic Oversight for read-only readiness information.
                </x-slot>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
