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
                    description="This is the complete version you are being asked to confirm. Official course registrations are created only after finalization."
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
                description="Each item is derived from current source records."
                icon="heroicon-o-list-bullet"
            >
                <ol class="space-y-4">
                    @foreach ([
                        ['Identity and Term', $readiness['identity'], 'Registrar resolves the authoritative learner source.'],
                        ['Current proposal', $readiness['proposal'], 'Registrar prepares and issues the proposal.'],
                        ['Your confirmation', $readiness['confirmation'], 'Review and confirm the issued version.'],
                        ['Protected placement', $readiness['placement'], 'Registrar resolves capacity, conflict, or deadline changes.'],
                        ['Accounting clearance', $readiness['finance'], 'Accounting resolves the current requirement.'],
                    ] as [$label, $ready, $recovery])
                        <li class="rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                            <div class="flex items-start gap-3">
                                <x-filament::icon
                                    :icon="$ready ? 'heroicon-o-check-circle' : 'heroicon-o-clock'"
                                    @class(['mt-0.5 h-5 w-5 shrink-0', 'text-success-600' => $ready, 'text-warning-600' => ! $ready])
                                />
                                <div>
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $label }}</p>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ $ready ? 'Ready' : $recovery }}</p>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
