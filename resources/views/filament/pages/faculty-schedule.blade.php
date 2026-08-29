<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            <x-slot name="heading">Exact Term and official version</x-slot>
            <x-slot name="description">
                Only current published assignments appear. Candidate schedules are not visible to Faculty.
            </x-slot>

            <div class="flex flex-wrap gap-2">
                @forelse ($terms as $option)
                    <x-filament::button
                        :color="$term?->id === $option->id ? 'primary' : 'gray'"
                        :outlined="$term?->id !== $option->id"
                        type="button"
                        wire:click="selectTerm({{ $option->id }})"
                    >
                        {{ $option->academicYear?->label }} · {{ $option->label }}
                    </x-filament::button>
                @empty
                    <p class="text-sm text-gray-600 dark:text-gray-300">No Term is available. Contact the Registrar.</p>
                @endforelse
            </div>

            @if ($term)
                <p class="mt-3 text-sm text-gray-600 dark:text-gray-300">
                    {{ $version ? 'Current official Timetable v'.$version->version : 'No current published timetable assigns you in this Term.' }}
                </p>
            @endif
        </x-filament::section>

        @if ($examinationPeriod)
            <x-filament::section icon="heroicon-o-information-circle">
                <x-slot name="heading">Examination Period</x-slot>
                <x-slot name="description">
                    {{ $examinationPeriod->opens_on->format('M j, Y') }}–{{ $examinationPeriod->closes_on->format('M j, Y') }} · Informational Term Calendar source only. Class-level examination scheduling is outside this page.
                </x-slot>
            </x-filament::section>
        @endif

        <section aria-labelledby="weekly-timetable" class="space-y-3">
            <div>
                <h2 id="weekly-timetable" class="text-lg font-semibold text-gray-950 dark:text-white">Weekly timetable</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">A day-by-day reading view of the same official meetings listed in the filterable table below.</p>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                @foreach (\App\Models\SectionMeeting::dayOptions() as $day => $label)
                    <x-filament::section compact>
                        <x-slot name="heading">{{ $label }}</x-slot>

                        <ol aria-label="{{ $label }} assignments" class="space-y-3">
                            @forelse ($meetingsByDay->get($day, collect()) as $meeting)
                                <li class="border-l-4 border-primary-600 pl-3 dark:border-primary-400">
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $meeting->schedulingDemand?->termOffering?->curriculumEntry?->courseSpecification?->course?->code }} · {{ $meeting->schedulingDemand?->sectionDeliveryGroup?->section?->code }}</p>
                                    <p class="text-sm text-gray-600 dark:text-gray-300">{{ substr($meeting->starts_at, 0, 5) }}–{{ substr($meeting->ends_at, 0, 5) }} · {{ $meeting->room?->code ?? 'TBA' }} · {{ \App\Models\SectionMeeting::modalityOptions()[$meeting->modality] ?? $meeting->modality }}</p>
                                </li>
                            @empty
                                <li class="text-sm text-gray-500 dark:text-gray-400">No assigned meeting.</li>
                            @endforelse
                        </ol>
                    </x-filament::section>
                @endforeach
            </div>
        </section>

        <x-filament::section>
            <x-slot name="heading">Accessible meeting table</x-slot>
            <x-slot name="description">Search and filter the same official assignments by Course, Section, room, or modality.</x-slot>
            {{ $this->table }}
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Affected revision history</x-slot>
            <x-slot name="description">Only published revision events that name you as the prior or successor Faculty appear here.</x-slot>

            <ul class="space-y-2">
                @forelse ($revisionEvents as $event)
                    <li class="rounded-lg border border-gray-200 p-3 text-sm dark:border-white/10">
                        <strong>{{ \App\Models\ScheduleRevisionEvent::changeTypeOptions()[$event->change_type] ?? str($event->change_type)->headline() }}</strong>
                        · Effective {{ $event->effective_date->format('M j, Y') }} · {{ $event->reason }}
                    </li>
                @empty
                    <li class="text-sm text-gray-500 dark:text-gray-400">No published revision currently affects your assignments in this Term.</li>
                @endforelse
            </ul>
        </x-filament::section>
    </div>
</x-filament-panels::page>
