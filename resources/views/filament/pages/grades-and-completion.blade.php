<x-filament-panels::page>
    <div class="mx-auto w-full max-w-4xl space-y-6">
        <x-filament::section>
            <x-slot name="heading">Registrar academic results</x-slot>
            <x-slot name="description">
                Start with the authoritative record you need to manage. Grade release, academic decisions,
                external competency, and lifecycle history remain attributable and append-only.
            </x-slot>
        </x-filament::section>

        <x-examination-period-summary :projection="$this->examinationPeriod()" />

        <x-filament::tabs aria-label="Grades and Completion sections">
            @foreach ($this->tabs() as $key => $label)
                <x-filament::tabs.item
                    :active="$this->viewTab === $key"
                    :aria-current="$this->viewTab === $key ? 'page' : null"
                    type="button"
                    wire:click="showTab('{{ $key }}')"
                >
                    {{ $label }}
                </x-filament::tabs.item>
            @endforeach
        </x-filament::tabs>

        @php($area = $this->activeWorkArea())
        <section aria-live="polite">
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
        </section>
    </div>
</x-filament-panels::page>
