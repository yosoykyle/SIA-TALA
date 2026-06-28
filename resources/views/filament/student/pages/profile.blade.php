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
    </div>
</x-filament-panels::page>
