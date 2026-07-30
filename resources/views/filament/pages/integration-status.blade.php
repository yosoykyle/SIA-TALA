<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">
            How to read system health
        </x-slot>

        <p class="text-sm text-gray-600 dark:text-gray-300">
            Local configuration shows whether TALA has the required non-secret setup. Operational evidence shows
            whether the application has observed a successful event or an unresolved exception. A complete
            configuration does not prove that an external provider completed its work.
        </p>
    </x-filament::section>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        @foreach ($this->integrations as $integration)
            <x-filament::section>
                <x-slot name="heading">
                    {{ $integration['name'] }}
                </x-slot>

                <div class="flex flex-wrap items-center gap-2">
                    <x-filament::badge color="gray">
                        Driver: {{ $integration['driver'] }}
                    </x-filament::badge>

                    <x-filament::badge color="gray">
                        {{ $integration['mode_label'] }}
                    </x-filament::badge>

                    <x-filament::badge :color="$integration['configuration_color']">
                        {{ $integration['configuration_label'] }}
                    </x-filament::badge>

                    <x-filament::badge :color="$integration['evidence_color']">
                        Evidence: {{ $integration['evidence_label'] }}
                    </x-filament::badge>
                </div>

                <dl class="mt-4 grid grid-cols-1 gap-2 text-sm">
                    @foreach ($integration['reference'] as $label => $value)
                        <div class="flex justify-between gap-4">
                            <dt class="text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                            <dd class="font-medium text-gray-950 dark:text-white">{{ $value !== '' ? $value : '—' }}</dd>
                        </div>
                    @endforeach
                </dl>

                <div class="mt-4 space-y-2 border-t border-gray-200 pt-4 text-sm dark:border-white/10">
                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">Owner</p>
                        <p class="text-gray-600 dark:text-gray-300">{{ $integration['owner'] }}</p>
                    </div>

                    <div>
                        <p class="font-medium text-gray-950 dark:text-white">Next action</p>
                        <p class="text-gray-600 dark:text-gray-300">{{ $integration['next_action'] }}</p>
                    </div>
                </div>
            </x-filament::section>
        @endforeach

        <x-filament::section>
            <x-slot name="heading">
                Document text extraction
            </x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Automated text extraction is not connected to an accepted application configuration, so TALA does
                not show a health claim for it. Uploaded document evidence remains available for authorized manual
                review.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
