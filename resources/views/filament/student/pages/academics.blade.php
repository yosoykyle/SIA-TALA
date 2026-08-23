<x-filament-panels::page>
    @if (! $student)
        <x-filament::section>
            <x-slot name="heading">Student record unavailable</x-slot>
            <p>Your account is not linked to an accessible Student profile. Contact the Registrar for assistance.</p>
        </x-filament::section>
    @else
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Your released academic record</x-slot>
                <x-slot name="description">
                    Results appear only after Registrar release. Missing or pending records are named explicitly; TALA does not calculate partial averages.
                </x-slot>
                <div class="flex flex-wrap gap-3 pt-2">
                    <x-filament::button :href="$scheduleUrl" tag="a" color="gray" icon="heroicon-m-calendar-days">Class schedule</x-filament::button>
                    <x-filament::button :href="$holdsUrl" tag="a" color="gray" icon="heroicon-m-exclamation-triangle">Holds and blockers</x-filament::button>
                    @if ($results->whereNotNull('event')->isNotEmpty())
                        <x-filament::button :href="$unofficialRecordUrl" tag="a" target="_blank" icon="heroicon-m-printer">Unofficial record</x-filament::button>
                    @endif
                </div>
            </x-filament::section>

            <div class="grid gap-4 md:grid-cols-3">
                <x-filament::section>
                    <x-slot name="heading">Cumulative GWA</x-slot>
                    <p class="text-2xl font-semibold">{{ $cumulative['value'] ?? 'Unavailable' }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $cumulative['through'] ? 'Through '.$cumulative['through'] : 'No complete included term yet' }}</p>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">Curriculum progress</x-slot>
                    <p class="text-2xl font-semibold">{{ $curriculum['completed_units'] }} units</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $curriculum['deficiency_count'] }} requirement(s) not yet completed</p>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">Enrollment guidance</x-slot>
                    <p class="text-lg font-semibold">{{ str($effect['effect'])->replace('_', ' ')->headline() }}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ $effect['reason'] }}</p>
                    <p class="mt-2 text-xs text-gray-500">Source: {{ $effect['source'] }}</p>
                </x-filament::section>
            </div>

            <x-filament::section>
                <x-slot name="heading">Completion readiness</x-slot>
                <x-slot name="description">Applying records your intent. Only Registrar can record conferral after every current source is ready.</x-slot>
                <p class="text-xl font-semibold">{{ str($completion['state'])->headline() }}</p>
                @if ($completion['blockers'] !== [])
                    <div class="mt-4 space-y-3" aria-label="Completion blockers">
                        @foreach ($completion['blockers'] as $blocker)
                            <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 dark:border-warning-700 dark:bg-warning-950">
                                <p class="font-medium">{{ $blocker['reason'] }}</p>
                                <p class="text-sm">Responsible: {{ $blocker['owner'] }}</p>
                                <p class="text-sm">Next step: {{ $blocker['recovery'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="mt-4 grid gap-4 lg:grid-cols-2">
                    <div>
                        <h3 class="font-semibold">Application history</h3>
                        @forelse ($graduationApplications as $application)
                            <p class="mt-2 text-sm">Version {{ $application->version }} · {{ $application->state }} · {{ $application->applied_at->format('M j, Y') }}</p>
                        @empty
                            <p class="mt-2 text-sm">No graduation application recorded.</p>
                        @endforelse
                    </div>
                    <div>
                        <h3 class="font-semibold">Conferral history</h3>
                        @forelse ($degreeConferrals as $conferral)
                            <p class="mt-2 text-sm">{{ $conferral->degree_name }} · {{ $conferral->conferred_on->format('M j, Y') }}</p>
                        @empty
                            <p class="mt-2 text-sm">No degree conferral recorded.</p>
                        @endforelse
                    </div>
                </div>
            </x-filament::section>

            @forelse ($terms as $term)
                @php($termResults = $results->where('term.id', $term->id))
                @php($average = $termAverages->get($term->id))
                <x-filament::section>
                    <x-slot name="heading">{{ $term->label }}</x-slot>
                    <x-slot name="description">
                        {{ $average['label'] }}: {{ $average['value'] ?? $average['reason'] }}
                    </x-slot>
                    <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-white/10">
                        <table class="w-full min-w-[42rem] text-left text-sm">
                            <thead class="bg-gray-50 dark:bg-white/5">
                                <tr><th class="px-4 py-3" scope="col">Course</th><th class="px-4 py-3" scope="col">Title</th><th class="px-4 py-3" scope="col">Units</th><th class="px-4 py-3" scope="col">Official result</th><th class="px-4 py-3" scope="col">Status</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                                @foreach ($termResults as $result)
                                    <tr>
                                        <td class="px-4 py-3 font-medium">{{ $result['course_specification']?->course?->code }}</td>
                                        <td class="px-4 py-3">{{ $result['course_specification']?->title }}</td>
                                        <td class="px-4 py-3">{{ number_format((float) $result['units'], 2) }}</td>
                                        <td class="px-4 py-3">{{ $result['result'] ?? 'Not released' }}</td>
                                        <td class="px-4 py-3">{{ $result['event']?->event_type ? str($result['event']->event_type)->replace('_', ' ')->headline() : 'Awaiting official release' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @empty
                <x-filament::section>
                    <x-slot name="heading">No academic results yet</x-slot>
                    <p>Official enrollment and released results will appear here. Your class schedule and holds remain available above.</p>
                </x-filament::section>
            @endforelse

            <div class="grid gap-4 lg:grid-cols-2">
                <x-filament::section>
                    <x-slot name="heading">External competency history</x-slot>
                    <x-slot name="description">Tracked evidence only. These records do not create grades, units, prerequisite credit, or financial effects.</x-slot>
                    <div class="space-y-3">
                        @forelse ($competencyResults as $result)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <p class="font-medium">{{ $result->requirement?->qualification_label ?? $result->requirement?->requirement_code }}</p>
                                <p>{{ str($result->outcome)->replace('_', ' ')->headline() }}{{ $result->is_current ? ' · Current' : ' · Superseded' }}</p>
                                <p class="text-xs text-gray-500">Recorded {{ $result->recorded_at?->format('M j, Y') }}</p>
                            </div>
                        @empty
                            <p>Not recorded.</p>
                        @endforelse
                    </div>
                </x-filament::section>
                <x-filament::section>
                    <x-slot name="heading">Lifecycle history</x-slot>
                    <x-slot name="description">Append-only Registrar-recorded leave, withdrawal, transfer, reactivation, and program-shift facts.</x-slot>
                    <div class="space-y-3">
                        @forelse ($lifecycleChanges as $change)
                            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                <p class="font-medium">{{ str($change->type)->replace('_', ' ')->headline() }}</p>
                                <p>{{ str($change->state)->replace('_', ' ')->headline() }}</p>
                                <p class="text-xs text-gray-500">Effective {{ $change->effective_on?->format('M j, Y') }}</p>
                            </div>
                        @empty
                            <p>No lifecycle change has been recorded.</p>
                        @endforelse
                    </div>
                </x-filament::section>
            </div>
        </div>
    @endif
</x-filament-panels::page>
