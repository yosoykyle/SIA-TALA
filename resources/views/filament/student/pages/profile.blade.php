<x-filament-panels::page>
    <div class="space-y-6">
        @foreach ($this->profileSections as $section)
            <section class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <h2 class="text-base font-semibold text-gray-950 dark:text-white">
                    {{ $section['heading'] }}
                </h2>

                <dl class="mt-4 grid gap-4 md:grid-cols-3">
                    @foreach ($section['items'] as $item)
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                                {{ $item['label'] }}
                            </dt>
                            <dd class="mt-1 text-sm text-gray-950 dark:text-white">
                                {{ $item['value'] }}
                            </dd>
                        </div>
                    @endforeach
                </dl>
            </section>
        @endforeach

        <x-filament::section
            heading="Correction guidance"
            description="This page is a read-only projection of the official Student record."
            icon="heroicon-o-information-circle"
        >
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Contact the Registrar to record a factual correction with authority, evidence, reason, effective time, and append-only history. Issued COR and TOR versions are never rewritten.
            </p>
        </x-filament::section>

        @if ($correctionHistory !== [])
            <x-filament::section heading="Recorded correction history" collapsible>
                <ol class="space-y-3">
                    @foreach ($correctionHistory as $event)
                        <li>
                            <p class="font-medium text-gray-950 dark:text-white">{{ $event['event'] }}</p>
                            <p class="text-sm text-gray-600 dark:text-gray-300">Effective {{ $event['effective_at'] }} · {{ $event['reason'] }}</p>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
