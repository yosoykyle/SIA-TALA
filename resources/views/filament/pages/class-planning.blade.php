<x-filament-panels::page>
    @php
        $term = $this->selectedTerm();
        $summary = $this->workflowSummary();
    @endphp

    @if (! $term || ! $summary)
        <x-filament::section>
            <div class="flex flex-col items-start gap-4 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">No academic term is configured</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        Create the academic year and term before planning classes or generating a timetable.
                    </p>
                </div>
            </div>
        </x-filament::section>
    @else
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">{{ $term->label }}</x-slot>
                <x-slot name="description">
                    {{ $term->academicYear?->label ?? 'Academic year not assigned' }} ·
                    {{ \App\Models\Term::stateOptions()[$term->state] ?? str($term->state)->headline() }}
                </x-slot>

                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ([
                        'Offerings' => $summary['counts']['offerings'],
                        'Sections' => $summary['counts']['sections'],
                        'Schedule requirements' => $summary['counts']['requirements'],
                        'Official meetings' => $summary['counts']['published_meetings'],
                    ] as $label => $value)
                        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                            <div class="text-2xl font-semibold text-gray-950 dark:text-white">{{ $value }}</div>
                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $label }}</div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>

            <div class="space-y-4">
                @foreach ($summary['stages'] as $index => $stage)
                    <x-filament::section>
                        <x-slot name="heading">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-sm font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300">
                                    {{ $index + 1 }}
                                </span>
                                <span>{{ $stage['title'] }}</span>
                                <x-filament::badge :color="$stage['color']">
                                    {{ $stage['status'] }}
                                </x-filament::badge>
                            </div>
                        </x-slot>
                        <x-slot name="description">{{ $stage['description'] }}</x-slot>

                        <div class="grid gap-5 lg:grid-cols-3">
                            <div class="lg:col-span-2">
                                <div class="text-sm font-medium text-gray-950 dark:text-white">Current state</div>
                                <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ $stage['summary'] }}</p>

                                <div class="mt-4 text-sm font-medium text-gray-950 dark:text-white">What blocks progress</div>
                                <p class="mt-1 text-sm leading-6 text-gray-600 dark:text-gray-400">{{ $stage['blocker'] }}</p>
                            </div>

                            <div class="flex flex-col items-start gap-4 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                                <div>
                                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Responsible role</div>
                                    <div class="mt-1 text-sm font-semibold text-gray-950 dark:text-white">{{ $stage['owner'] }}</div>
                                </div>

                                <div>
                                    <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Next action</div>
                                    <x-filament::button
                                        :href="$stage['action_url']"
                                        tag="a"
                                        color="gray"
                                        class="mt-2"
                                    >
                                        {{ $stage['action_label'] }}
                                    </x-filament::button>
                                </div>
                            </div>
                        </div>
                    </x-filament::section>
                @endforeach
            </div>
        </div>
    @endif
</x-filament-panels::page>
