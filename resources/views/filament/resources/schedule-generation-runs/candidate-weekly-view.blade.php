<x-filament::section>
    <x-slot name="heading">Candidate weekly view</x-slot>
    <x-slot name="description">
        This visual groups the same non-official assignments shown in the native filterable table below. It does not support drag-and-drop or bypass full-candidate revalidation.
    </x-slot>

    @if ($hasRows)
        <div class="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3" aria-label="Candidate meetings by teaching day">
            @foreach ($days as $day)
                @if ($day['rows'] !== [])
                    <section aria-labelledby="candidate-day-{{ \Illuminate\Support\Str::slug($day['label']) }}">
                        <h3 id="candidate-day-{{ \Illuminate\Support\Str::slug($day['label']) }}" class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $day['label'] }}
                        </h3>
                        <ol class="mt-2 space-y-2">
                            @foreach ($day['rows'] as $row)
                                <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $row['time'] }} · {{ $row['course'] }} · {{ $row['section'] }}</p>
                                    <p class="mt-1 text-gray-600 dark:text-gray-300">{{ $row['faculty'] }} · {{ $row['place'] }} · {{ str($row['status'])->headline() }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </section>
                @endif
            @endforeach
        </div>
    @else
        <p class="text-sm text-gray-600 dark:text-gray-300">No candidate meetings are available. Review the typed solver outcome and its recovery action.</p>
    @endif
</x-filament::section>
