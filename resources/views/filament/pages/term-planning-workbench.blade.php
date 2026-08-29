<x-filament-panels::page>
    <div class="space-y-6">
        <section aria-labelledby="term-selector" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <h2 id="term-selector" class="text-base font-semibold text-gray-950 dark:text-white">Exact Term</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($terms as $option)
                    <button type="button" wire:click="selectTerm({{ $option->id }})" @class([
                        'rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600',
                        'bg-primary-600 text-white' => $term?->id === $option->id,
                        'bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-100 dark:hover:bg-white/15' => $term?->id !== $option->id,
                    ])>
                        {{ $option->academicYear?->label }} · {{ $option->label }}
                    </button>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-300">No Term exists yet. Registrar can create one from Term records.</p>
                @endforelse
            </div>
        </section>

        @if ($term)
            <x-filament::tabs aria-label="Term planning sections">
                    @foreach ($tabs as $key => $label)
                        <x-filament::tabs.item
                            :active="$viewTab === $key"
                            :aria-current="$viewTab === $key ? 'page' : null"
                            type="button"
                            wire:click="showTab('{{ $key }}')"
                        >
                            {{ $label }}
                        </x-filament::tabs.item>
                    @endforeach
            </x-filament::tabs>

            <section aria-live="polite" class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"><p class="text-sm text-gray-500">Calendar package</p><p class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $activePackage ? 'Active v'.$activePackage->version : 'Action required' }}</p></article>
                    <article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"><p class="text-sm text-gray-500">Class Offerings</p><p class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $counts['confirmed'] }} / {{ $counts['classes'] }} confirmed</p></article>
                    <article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"><p class="text-sm text-gray-500">Generation runs</p><p class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $counts['runs'] }}</p></article>
                    <article class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"><p class="text-sm text-gray-500">Published timetable</p><p class="mt-1 font-semibold text-gray-950 dark:text-white">{{ $currentVersion ? 'v'.$currentVersion->version.' · '.$currentVersion->meetings_count.' meetings' : 'Not published' }}</p></article>
                </div>

                @if ($readiness && ! $readiness['ready'])
                    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-400/30 dark:bg-amber-950/30">
                        <h2 class="font-semibold text-amber-950 dark:text-amber-100">Readiness actions required</h2>
                        <ul class="mt-2 space-y-2 text-sm text-amber-900 dark:text-amber-100">
                            @foreach ($readiness['blockers'] as $blocker)
                                <li><strong>{{ $blocker['source'] }}:</strong> {{ $blocker['reason'] }} {{ $blocker['next_action'] }}</li>
                            @endforeach
                        </ul>
                    </div>
                @elseif ($readiness)
                    <div role="status" class="rounded-xl border border-green-300 bg-green-50 p-4 text-sm font-medium text-green-950 dark:border-green-400/30 dark:bg-green-950/30 dark:text-green-100">All required checks passed.</div>
                @endif

                <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $tabs[$viewTab] }}</h2>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                        @switch($viewTab)
                            @case('overview') Review approved dates, windows, teaching grid, exceptions, and authority evidence. @break
                            @case('classes') Confirm cohorts and Regular or authority-backed Additional Class Offerings. Sharing is recorded through explicit cohort relationships. @break
                            @case('resources') Review Faculty declarations, qualifications, rooms, features, institutional unavailability, and commitments. Faculty records their own availability from My Availability. @break
                            @case('generate') Generate the complete exact-Term candidate, inspect outcomes, record non-official review, and open Candidate Correction only for a current candidate. @break
                            @case('published') Inspect current and superseded immutable timetable versions and version-bound output. @break
                        @endswitch
                    </p>
                    <a href="{{ $destinations[$viewTab] }}" class="mt-4 inline-flex rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2">
                        Open {{ $tabs[$viewTab] }} records
                    </a>
                    @if ($viewTab === 'generate')
                        <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                            <strong>Candidate Correction:</strong> use the selected candidate's actions for one-meeting correction or a complete repair preview. Correction is not a separate planning destination.
                        </p>
                    @endif
                    @if ($viewTab === 'published')
                        <div class="mt-5 overflow-x-auto">
                            <table class="w-full text-left text-sm">
                                <thead><tr><th class="p-2">Version</th><th class="p-2">State</th><th class="p-2">Meetings</th><th class="p-2">Published</th><th class="p-2">Output</th></tr></thead>
                                <tbody>
                                    @forelse ($versions as $version)
                                        <tr class="border-t border-gray-200 dark:border-white/10">
                                            <td class="p-2">v{{ $version->version }}</td>
                                            <td class="p-2 font-medium">{{ $version->state }}</td>
                                            <td class="p-2">{{ $version->meetings_count }}</td>
                                            <td class="p-2">{{ $version->published_at?->format('M j, Y g:i A') }}</td>
                                            <td class="p-2"><a class="text-primary-700 underline dark:text-primary-300" target="_blank" href="{{ route('timetable.version.print', $version) }}">A4 landscape</a></td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="p-3 text-gray-500">No immutable timetable version has been published for this exact Term.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif
                    @if ($readOnly)
                        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Read-only oversight: publication and correction controls remain unavailable.</p>
                    @endif
                </article>
            </section>
        @elseif ($terms->isNotEmpty())
            <x-filament::section icon="heroicon-o-cursor-arrow-rays">
                <x-slot name="heading">Select one exact Term</x-slot>
                <x-slot name="description">
                    More than one planning context is available, or no Term has an active Calendar Package. Choose a Term above before reviewing records or taking an action.
                </x-slot>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
