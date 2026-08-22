<x-filament-panels::page>
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Registrar academic results</x-slot>
            <x-slot name="description">
                Start with the authoritative record you need to manage. Grade release, academic decisions,
                external competency, and lifecycle history remain attributable and append-only.
            </x-slot>
        </x-filament::section>

        @foreach ($this->workAreas() as $area)
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
        @endforeach
    </div>
</x-filament-panels::page>
