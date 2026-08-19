<x-filament-panels::page>
    <div class="space-y-6">
        <section aria-labelledby="catalog-summary" class="space-y-3">
            <div>
                <h2 id="catalog-summary" class="text-lg font-semibold text-gray-950 dark:text-white">Academic authority at a glance</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">One connected entry point for program authority, course revisions, curricula, and import evidence.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($summary as $item)
                    <article class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-300">{{ $item['label'] }}</p>
                        <p class="mt-1 text-3xl font-semibold text-gray-950 dark:text-white">{{ number_format($item['value']) }}</p>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $item['detail'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        @if ($readOnly)
            <div role="status" class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-400/30 dark:bg-blue-950/40 dark:text-blue-100">
                Academic Head access is read-only. Registrar remains the recording and activation authority.
            </div>
        @endif

        <section aria-labelledby="catalog-actions" class="space-y-3">
            <h2 id="catalog-actions" class="text-lg font-semibold text-gray-950 dark:text-white">Catalog & Curricula workbench</h2>
            <div class="grid gap-4 lg:grid-cols-2">
                @foreach ($destinations as $destination)
                    <a href="{{ $destination['url'] }}" class="group rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-primary-500 hover:ring-2 hover:ring-primary-500/20 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 dark:border-white/10 dark:bg-gray-900">
                        <span class="font-semibold text-gray-950 group-hover:text-primary-700 dark:text-white dark:group-hover:text-primary-300">{{ $destination['label'] }}</span>
                        <span class="mt-1 block text-sm text-gray-600 dark:text-gray-300">{{ $destination['description'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>
    </div>
</x-filament-panels::page>
