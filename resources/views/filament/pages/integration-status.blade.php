<x-filament-panels::page>
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

                    <x-filament::badge :color="$integration['live_mode'] ? 'warning' : 'gray'">
                        {{ $integration['live_mode'] ? 'Live mode' : 'Practice / mock mode' }}
                    </x-filament::badge>

                    <x-filament::badge :color="$integration['configured'] ? 'success' : 'danger'">
                        {{ $integration['configured'] ? 'Configured ✓' : 'Not configured ✗' }}
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
            </x-filament::section>
        @endforeach

        <x-filament::section>
            <x-slot name="heading">
                OCR (document evidence)
            </x-slot>

            <p class="text-sm text-gray-500 dark:text-gray-400">
                Not yet wired to a config source. <code>.env.example</code> declares <code>TALA_OCR_*</code>
                variables, but no <code>config/*.php</code> file or application code reads them yet. This
                status row is intentionally omitted rather than fabricated; see TAL-92D handshake notes.
            </p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
