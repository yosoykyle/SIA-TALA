<x-filament-panels::page>
    @if (! $enrollment)
        <x-filament::section
            heading="Registration has not started"
            description="When the Term registration window is open, start one exact-Term case here. Your Student identity and prior records remain unchanged."
            icon="heroicon-o-clipboard-document-list"
        >
            <p class="text-sm text-gray-600 dark:text-gray-300">
                Starting creates the case only. The Registrar still prepares the proposal, you confirm it, Accounting clears the current requirement, and the Registrar finalizes the official enrollment.
            </p>
        </x-filament::section>
    @else
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.4fr)_minmax(20rem,0.6fr)]">
            <div class="space-y-6">
                <x-filament::section
                    :heading="$enrollment->term?->label ?? 'Registration Case'"
                    :description="$enrollment->case_reference"
                    icon="heroicon-o-identification"
                >
                    <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Outcome</dt>
                            <dd class="mt-1 font-semibold text-gray-950 dark:text-white">{{ str($enrollment->canonical_outcome)->headline() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Selection basis</dt>
                            <dd class="mt-1 font-semibold text-gray-950 dark:text-white">{{ str($enrollment->selection_basis)->headline() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Current proposal</dt>
                            <dd class="mt-1 font-semibold text-gray-950 dark:text-white">
                                {{ $proposal ? 'Version '.$proposal->version.' · '.$proposal->state : 'Not prepared' }}
                            </dd>
                        </div>
                    </dl>
                </x-filament::section>

                <x-filament::section
                    heading="Current proposal"
                    :description="'Why these subjects: the Registrar applied '.str($enrollment->selection_basis)->headline().' to your assigned Curriculum Version and exact Term. Official course registrations are created only after finalization.'"
                    icon="heroicon-o-document-check"
                >
                    @if ($proposal && $proposal->items->isNotEmpty())
                        <div class="overflow-x-auto rounded-xl ring-1 ring-gray-950/10 dark:ring-white/10">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                    <tr>
                                        <th class="px-4 py-3">Course</th>
                                        <th class="px-4 py-3">Class</th>
                                        <th class="px-4 py-3">Units</th>
                                        <th class="px-4 py-3">Placement</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-950/5 dark:divide-white/10">
                                    @foreach ($proposal->items as $item)
                                        <tr>
                                            <td class="px-4 py-3">
                                                <span class="font-semibold text-gray-950 dark:text-white">{{ $item->course_code_snapshot }}</span>
                                                <span class="block text-gray-600 dark:text-gray-300">{{ $item->course_title_snapshot }}</span>
                                            </td>
                                            <td class="px-4 py-3">{{ $item->section?->code ?? $item->section_id }}</td>
                                            <td class="px-4 py-3 tabular-nums">{{ $item->units_snapshot }}</td>
                                            <td class="px-4 py-3">{{ str($item->reservation?->status ?? 'Not placed')->headline() }}</td>
                                        </tr>
                                        @if (($item->meeting_snapshot ?? []) !== [])
                                            <tr>
                                                <td colspan="4" class="px-4 pb-4 text-xs text-gray-600 dark:text-gray-300">
                                                    @foreach ($item->meeting_snapshot as $meeting)
                                                        {{ \App\Models\SectionMeeting::dayOptions()[(int) $meeting['day_of_week']] ?? 'Day not recorded' }}
                                                        {{ substr((string) $meeting['starts_at'], 0, 5) }}–{{ substr((string) $meeting['ends_at'], 0, 5) }}
                                                        · {{ $meeting['room_label'] ?? 'Room not recorded' }}
                                                        · {{ $meeting['faculty_name'] ?? 'Faculty not recorded' }}{{ ! $loop->last ? '; ' : '' }}
                                                    @endforeach
                                                </td>
                                            </tr>
                                        @elseif ($item->scheduling_treatment_snapshot === \App\Models\CourseSpecification::SchedulingExternallyArranged)
                                            <tr><td colspan="4" class="px-4 pb-4 text-xs text-gray-600 dark:text-gray-300">Approved no-meeting treatment: externally arranged.</td></tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-sm text-gray-600 dark:text-gray-300">The Registrar has not prepared the current proposal yet.</p>
                    @endif
                </x-filament::section>

                @if (($readiness['shortages'] ?? []) !== [])
                    <x-filament::section
                        heading="Placement needs Registrar action"
                        description="No waitlist or silent class move was created."
                        icon="heroicon-o-exclamation-triangle"
                    >
                        <div class="space-y-3">
                            @foreach ($readiness['shortages'] as $shortage)
                                <div class="rounded-xl bg-warning-50 p-4 text-sm text-warning-950 dark:bg-warning-500/10 dark:text-warning-100">
                                    <p class="font-semibold">{{ $shortage['course'] }} · {{ $shortage['section'] }}</p>
                                    <p class="mt-1">Owner: {{ $shortage['owner'] }}. {{ $shortage['recovery'] }}</p>
                                    <p class="mt-1">Alternatives: {{ $shortage['alternatives'] === [] ? 'None currently available' : implode(', ', $shortage['alternatives']) }}</p>
                                </div>
                            @endforeach
                        </div>
                    </x-filament::section>
                @endif
            </div>

            <x-filament::section
                heading="Five checkpoints"
                description="Each item names its authoritative source, responsible owner, consequence, and recovery."
                icon="heroicon-o-list-bullet"
            >
                <ol class="space-y-4">
                    @php($checkpointRows = [
                        ['Student eligibility', $readiness['eligibility'] && $readiness['identity'], 'Admissions/Student record', 'Registrar', 'Registration cannot proceed without a current eligible identity source.', 'Contact the Registrar if the source is not current.'],
                        ['Confirmed proposed subjects', $readiness['confirmation'], 'Registration Proposal version '.($proposal?->version ?? 'not prepared'), 'Learner', 'Unconfirmed subjects cannot become official registrations.', 'Review and confirm the current issued proposal.'],
                        ['Valid class placement', $readiness['placement'], 'Published Timetable and reservations', 'Registrar', 'Unresolved capacity or conflict blocks finalization.', 'The Registrar must resolve the listed placement blocker.'],
                        ['Accounting clearance', $readiness['finance'], 'Enrollment Payment Requirement', 'Accounting', 'An unsatisfied exact requirement blocks finalization.', 'Use the current Finance handoff or contact Accounting.'],
                        ['Registrar finalization', $enrollment->canonical_outcome === \App\Models\Enrollment::OutcomeOfficiallyEnrolled, 'Registration Case '.$enrollment->case_reference, 'Registrar', 'Only atomic finalization creates Official Enrollment and COR.', $readiness['ready'] ? 'All checkpoints are ready for Registrar finalization.' : 'Resolve the earlier checkpoint shown above.'],
                    ])
                    @php($currentCheckpoint = collect($checkpointRows)->search(fn ($row) => ! $row[1]))
                    @foreach ($checkpointRows as $index => [$label, $ready, $source, $owner, $consequence, $recovery])
                        <li class="rounded-xl bg-gray-50 p-4 dark:bg-white/5" @if ($currentCheckpoint === $index) aria-current="step" @endif>
                            <div class="flex items-start gap-3">
                                <x-filament::icon
                                    :icon="$ready ? 'heroicon-o-check-circle' : 'heroicon-o-clock'"
                                    @class(['mt-0.5 h-5 w-5 shrink-0', 'text-success-600' => $ready, 'text-warning-600' => ! $ready])
                                />
                                <div>
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $label }}</p>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $ready ? 'Verified from '.$source.'.' : $recovery }}</p>
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Owner: {{ $owner }} · As of {{ $enrollment->updated_at?->timezone(config('app.display_timezone'))->format('M d, Y g:i A') }} · {{ $consequence }}</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        </div>

        @if ($corHistory !== [])
            <x-filament::section
                heading="COR versions"
                description="Immutable current, historical, and superseded versions are ordered newest first. Current finance remains in Student Finance and the SOA."
                icon="heroicon-o-document-duplicate"
            >
                <div class="space-y-3">
                    @foreach ($corHistory as $corVersion)
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl ring-1 ring-gray-950/10 p-4 dark:ring-white/10">
                            <p><strong>{{ $corVersion['term'] }} · COR version {{ $corVersion['version'] }}</strong><br><span class="text-sm text-gray-600 dark:text-gray-300">{{ $corVersion['status'] }}</span></p>
                            <x-filament::button :href="$corVersion['url']" tag="a" target="_blank" size="sm" icon="heroicon-o-printer">Open COR</x-filament::button>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif
    @endif
</x-filament-panels::page>
