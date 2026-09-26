<x-filament-panels::page>
    <div class="space-y-6">
        <section aria-labelledby="term-selector" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="term-selector" class="text-base font-semibold text-gray-950 dark:text-white">Exact Term</h2>
                @if ($term)
                    <span @class([
                        'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset',
                        'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950 dark:text-emerald-300' => in_array($term->state, [\App\Models\Term::StateActive, 'Active', 'ACTIVE'], true),
                        'bg-slate-100 text-slate-700 ring-slate-600/20 dark:bg-gray-800 dark:text-gray-300' => ! in_array($term->state, [\App\Models\Term::StateActive, 'Active', 'ACTIVE'], true),
                    ])>
                        Term state: {{ ucfirst(strtolower($term->state)) }}
                    </span>
                @endif
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
                @forelse ($terms as $option)
                    <button type="button" wire:click="selectTerm({{ $option->id }})" @class([
                        'rounded-lg px-3 py-2 text-sm font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600',
                        'bg-primary-600 text-white' => $term?->id === $option->id,
                        'bg-gray-100 text-gray-800 hover:bg-gray-200 dark:bg-white/10 dark:text-gray-100 dark:hover:bg-white/15' => $term?->id !== $option->id,
                    ])>
                        {{ $option->academicYear?->label }} · {{ $option->label }}
                        <span class="ml-1 text-xs opacity-75 font-normal">({{ ucfirst(strtolower($option->state)) }})</span>
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

                @if ($draftPackages->isNotEmpty())
                    <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                        <h2 class="font-semibold text-gray-950 dark:text-white">Draft Calendar Packages</h2>
                        <div class="mt-3 space-y-3">
                            @foreach ($draftPackages as $item)
                                @php
                                    $pkg = $item['package'];
                                    $pkgReadiness = $item['readiness'];
                                @endphp
                                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="font-medium text-gray-900 dark:text-white">
                                            Draft v{{ $pkg->version }} · {{ $pkg->authority_reference ?? 'No authority reference' }}
                                        </span>
                                        @if ($pkgReadiness['ready'])
                                            <span role="status" class="inline-flex items-center gap-1 rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-950/50 dark:text-green-300">
                                                Ready for activation — all required checks passed
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold text-amber-800 dark:bg-amber-950/50 dark:text-amber-300">
                                                Action required ({{ count($pkgReadiness['blockers']) }} blocker{{ count($pkgReadiness['blockers']) === 1 ? '' : 's' }})
                                            </span>
                                        @endif
                                    </div>
                                    @if (! $pkgReadiness['ready'])
                                        <ul class="mt-2 space-y-1 text-sm text-amber-900 dark:text-amber-200">
                                            @foreach ($pkgReadiness['blockers'] as $blocker)
                                                <li>
                                                    <strong>{{ $blocker['source'] }}:</strong> {{ $blocker['reason'] }}
                                                    <span class="text-xs text-gray-600 dark:text-gray-400">Action: {{ $blocker['next_action'] }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($viewTab === 'generate')
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900 space-y-6">
                        <!-- Institutional Header & Context -->
                        <div class="border-b border-slate-200 pb-4 dark:border-white/10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold tracking-wider text-blue-900 uppercase dark:text-blue-300">Servitech Institute Asia Inc. · Office of the University Registrar</span>
                                    <span class="text-[10px] text-slate-400">·</span>
                                    <span class="text-[10px] font-medium text-slate-500 dark:text-slate-400">Powered by TALA</span>
                                </div>
                                <h2 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white mt-0.5">CP-SAT Timetable Optimization & Candidate Review</h2>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    Exact-term candidate generation, collision-free time-block matrix verification, and official publication controls for <strong>{{ $term->label }}</strong>.
                                </p>
                                <p class="mt-2 text-xs text-slate-500 dark:text-slate-400">
                                    <strong>Candidate Correction:</strong> use the selected candidate's actions for one-meeting correction or a complete repair preview. Correction is not a separate planning destination.
                                </p>
                            </div>
                            @if ($allRuns->count() > 1)
                                <div class="flex items-center gap-2">
                                    <label for="run-selector" class="text-xs font-semibold text-slate-600 dark:text-slate-400 whitespace-nowrap">Solver Run:</label>
                                    <select id="run-selector" wire:change="selectRun($event.target.value)" class="text-xs rounded-lg border-slate-300 py-1.5 px-3 bg-white dark:bg-gray-800 dark:border-white/10 dark:text-white font-medium shadow-sm">
                                        @foreach ($allRuns as $runOption)
                                            <option value="{{ $runOption->id }}" @selected($activeRun?->id === $runOption->id)>
                                                Run #{{ $runOption->id }} · {{ str($runOption->status)->headline() }} ({{ $runOption->created_at->format('M j, g:i A') }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>

                        @if ($activeRun)
                            <!-- Choice 3: Collapsible Solver Control Deck -->
                            <div class="rounded-xl border border-slate-200 bg-slate-50/80 p-4 dark:border-white/10 dark:bg-white/5 space-y-4">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="flex flex-wrap items-center gap-2.5">
                                        <span class="inline-flex items-center rounded-md bg-blue-900 px-2.5 py-1 text-xs font-bold text-white shadow-sm dark:bg-blue-800">
                                            Run #{{ $activeRun->id }} · Candidate v{{ $activeRun->candidate_version ?? 1 }}
                                        </span>
                                        @php
                                            $statusColor = \App\Models\ScheduleGenerationRun::statusColors()[$activeRun->status] ?? 'gray';
                                        @endphp
                                        <span @class([
                                            'inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold',
                                            'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' => $statusColor === 'warning',
                                            'bg-blue-100 text-blue-800 dark:bg-blue-950/60 dark:text-blue-300' => $statusColor === 'info',
                                            'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' => $statusColor === 'success',
                                            'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300' => $statusColor === 'danger',
                                            'bg-primary-100 text-primary-800 dark:bg-primary-950/60 dark:text-primary-300' => $statusColor === 'primary',
                                            'bg-gray-100 text-gray-800 dark:bg-gray-800 dark:text-gray-300' => $statusColor === 'gray',
                                        ])>
                                            {{ \App\Models\ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? str($activeRun->status)->headline() }}
                                        </span>

                                        @if (!empty($solverOutcome['status']))
                                            <span @class([
                                                'inline-flex items-center rounded-md px-2 py-0.5 text-xs font-semibold',
                                                'bg-emerald-50 text-emerald-700 border border-emerald-300 dark:bg-emerald-950/40 dark:text-emerald-300' => in_array($solverOutcome['status'], ['OPTIMAL', 'FEASIBLE'], true),
                                                'bg-red-50 text-red-700 border border-red-300 dark:bg-red-950/40 dark:text-red-300' => in_array($solverOutcome['status'], ['INFEASIBLE', 'MODEL_INVALID'], true),
                                                'bg-gray-50 text-gray-700 border border-gray-300 dark:bg-gray-800 dark:text-gray-300' => !in_array($solverOutcome['status'], ['OPTIMAL', 'FEASIBLE', 'INFEASIBLE', 'MODEL_INVALID'], true),
                                            ])>
                                                CP-SAT: {{ $solverOutcome['status'] }}
                                            </span>
                                        @endif

                                        @if ($activeRun->candidate_state)
                                            <span class="inline-flex items-center rounded-md border border-slate-300 bg-white px-2 py-0.5 text-xs font-medium text-slate-700 dark:border-white/10 dark:bg-gray-800 dark:text-gray-300">
                                                Review: {{ str($activeRun->candidate_state)->headline() }}
                                            </span>
                                        @endif

                                        @if ($isControlDeckCollapsed)
                                            <span class="hidden sm:inline-flex items-center text-xs text-slate-500 font-medium">
                                                · {{ ($publicationSummary['conflicts'] ?? 0) === 0 ? '0 Collisions' : ($publicationSummary['conflicts'] . ' Conflicts') }}
                                                · Soft: {{ number_format((float) ($qualityMeasures['soft_penalty'] ?? $activeRun->objective_value ?? 0), 2) }}
                                            </span>
                                        @endif
                                    </div>

                                    <!-- Operational Actions & Collapse Toggle -->
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if (!$readOnly)
                                            <button type="button" wire:click="mountAction('generateTimetable')" class="inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-blue-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-700">
                                                <x-filament::icon icon="heroicon-o-paper-airplane" class="h-4 w-4" />
                                                <span>Generate Timetable</span>
                                            </button>

                                            @if ($this->canReviewActiveRun())
                                                <button type="button" wire:click="mountAction('acceptCandidate')" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-1">
                                                    <x-filament::icon icon="heroicon-o-check" class="h-4 w-4" />
                                                    <span>Accept Candidate</span>
                                                </button>
                                                <button type="button" wire:click="mountAction('rejectCandidate')" class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-red-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-600 focus-visible:ring-offset-1">
                                                    <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                                                    <span>Reject Candidate</span>
                                                </button>
                                            @endif

                                            @if ($this->canPublishActiveRun())
                                                <button type="button" wire:click="mountAction('publishOfficialTimetable')" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-3.5 py-1.5 text-xs font-bold text-white shadow hover:bg-emerald-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-1">
                                                    <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4" />
                                                    <span>Publish Official Timetable</span>
                                                </button>
                                            @endif

                                            @if ($this->canRetryActiveRun())
                                                <button type="button" wire:click="mountAction('retrySolverRun')" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-amber-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-1">
                                                    <x-filament::icon icon="heroicon-o-arrow-path" class="h-4 w-4" />
                                                    <span>Retry Solver</span>
                                                </button>
                                            @endif

                                            <a href="{{ \App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $activeRun]) }}" target="_blank" class="inline-flex items-center gap-1 text-xs text-blue-700 hover:text-blue-600 font-medium underline dark:text-blue-400 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600">
                                                <span>Full Audit</span>
                                                <x-filament::icon icon="heroicon-o-arrow-top-right-on-square" class="h-3 w-3" />
                                            </a>
                                        @else
                                            <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">Read-only oversight</span>
                                        @endif

                                        <!-- Collapse / Expand Deck Toggle Button -->
                                        <button type="button" wire:click="toggleControlDeck" class="inline-flex items-center gap-1 rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 shadow-sm dark:border-white/10 dark:bg-gray-800 dark:text-slate-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-1" title="{{ $isControlDeckCollapsed ? 'Expand solver metrics and diagnostics' : 'Collapse solver console to maximize canvas' }}">
                                            <x-filament::icon :icon="$isControlDeckCollapsed ? 'heroicon-o-chevron-down' : 'heroicon-o-chevron-up'" class="h-3.5 w-3.5" />
                                            <span>{{ $isControlDeckCollapsed ? 'Expand Deck' : 'Collapse Deck' }}</span>
                                        </button>
                                    </div>
                                </div>

                                @if (!$isControlDeckCollapsed)
                                    <!-- Quality Measures Summary Bar (Expanded) -->
                                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2 text-xs">
                                        <div class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-800 shadow-sm">
                                            <span class="text-slate-500 dark:text-slate-400">Hard Conflicts</span>
                                            <div class="mt-1 flex items-center gap-1.5">
                                                @if (($publicationSummary['conflicts'] ?? 0) === 0)
                                                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                                    <span class="font-bold text-emerald-700 dark:text-emerald-400">0 Collisions</span>
                                                @else
                                                    <span class="h-2 w-2 rounded-full bg-red-500 animate-pulse"></span>
                                                    <span class="font-bold text-red-700 dark:text-red-400">{{ $publicationSummary['conflicts'] }} Conflicts</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-800 shadow-sm">
                                            <span class="text-slate-500 dark:text-slate-400">Soft Penalty</span>
                                            <p class="mt-1 font-mono font-bold text-slate-900 dark:text-white">
                                                {{ number_format((float) ($qualityMeasures['soft_penalty'] ?? $activeRun->objective_value ?? 0), 2) }}
                                            </p>
                                        </div>
                                        <div class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-800 shadow-sm">
                                            <span class="text-slate-500 dark:text-slate-400">Seat Waste</span>
                                            <p class="mt-1 font-mono font-bold text-slate-900 dark:text-white">
                                                {{ $qualityMeasures['room_seat_waste'] ?? 0 }} seats
                                            </p>
                                        </div>
                                        <div class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-800 shadow-sm">
                                            <span class="text-slate-500 dark:text-slate-400">Faculty Idle</span>
                                            <p class="mt-1 font-mono font-bold text-slate-900 dark:text-white">
                                                {{ $qualityMeasures['faculty_idle_time'] ?? 0 }}h
                                            </p>
                                        </div>
                                        <div class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-800 shadow-sm">
                                            <span class="text-slate-500 dark:text-slate-400">Cohort Idle</span>
                                            <p class="mt-1 font-mono font-bold text-slate-900 dark:text-white">
                                                {{ $qualityMeasures['cohort_idle_time'] ?? 0 }}h
                                            </p>
                                        </div>
                                        <div class="rounded-lg border border-slate-200 bg-white p-2.5 dark:border-white/10 dark:bg-gray-800 shadow-sm">
                                            <span class="text-slate-500 dark:text-slate-400">Runtime</span>
                                            <p class="mt-1 font-mono font-bold text-slate-900 dark:text-white">
                                                {{ $activeRun->runtime_ms ? round($activeRun->runtime_ms / 1000, 2) . 's' : 'N/A' }}
                                            </p>
                                        </div>
                                    </div>

                                    <!-- Infeasible or Failed Diagnostics Card -->
                                    @if ($activeRun->status === \App\Models\ScheduleGenerationRun::StatusFailed || !empty($solverFailure) || in_array($solverOutcome['status'] ?? '', ['INFEASIBLE', 'MODEL_INVALID'], true))
                                        <div class="rounded-xl border border-red-300 bg-red-50/90 p-4 dark:border-red-900/50 dark:bg-red-950/30 space-y-3">
                                            <div class="flex items-start gap-3">
                                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-6 w-6 text-red-600 shrink-0 mt-0.5" />
                                                <div class="space-y-1">
                                                    <h3 class="font-bold text-red-950 dark:text-red-200">
                                                        Solver Outcome: {{ $solverFailure['code'] ?? ($solverOutcome['status'] ?? 'Generation Failure') }}
                                                    </h3>
                                                    <p class="text-sm text-red-900 dark:text-red-300">
                                                        {{ $solverFailure['message'] ?? 'The CP-SAT solver proved that no schedule exists satisfying all hard constraints for this term.' }}
                                                    </p>
                                                </div>
                                            </div>

                                            @if (!empty($solverOutcome['conflicts']) && is_array($solverOutcome['conflicts']))
                                                <div class="mt-2 rounded-lg border border-red-200 bg-white p-3 dark:border-red-900/30 dark:bg-gray-900">
                                                    <p class="text-xs font-semibold uppercase tracking-wider text-red-800 dark:text-red-300">Identified Constraint Collisions:</p>
                                                    <ul class="mt-1.5 space-y-1 text-xs text-red-900 dark:text-red-200">
                                                        @foreach ($solverOutcome['conflicts'] as $conflict)
                                                            <li class="flex items-center gap-1.5">
                                                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-red-600"></span>
                                                                <span><strong>{{ str($conflict['type'] ?? 'Conflict')->headline() }}:</strong> {{ $conflict['description'] ?? json_encode($conflict) }}</span>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </div>
                                            @endif

                                            <div class="rounded-lg bg-red-100/60 p-3 text-xs text-red-950 dark:bg-red-900/20 dark:text-red-200 space-y-1">
                                                <p class="font-semibold">Actionable Recovery Steps:</p>
                                                <ol class="list-decimal list-inside space-y-0.5">
                                                    <li>Check Classroom Capacities: Under <strong>Teaching Resources</strong>, ensure room capacities meet section demand.</li>
                                                    <li>Check Faculty Availability: Under <strong>Teaching Resources</strong>, review faculty declarations for time clashes.</li>
                                                    <li>Check Calendar Windows: Under <strong>Overview</strong>, ensure teaching grid exceptions do not block required hours.</li>
                                                    <li>When ready, click <strong>Retry Solver</strong> or <strong>Generate Timetable</strong> above.</li>
                                                </ol>
                                            </div>
                                        </div>
                                    @endif
                                @endif
                            </div>

                            <!-- Choice 3: Candidate Representation Toolbar (Sub-Views + Filters) -->
                            <div class="space-y-4">
                                <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-slate-200 pb-3 dark:border-white/10">
                                    <!-- Sub-View Switcher -->
                                    <div class="inline-flex rounded-lg border border-slate-300 p-0.5 bg-slate-100 dark:border-slate-700 dark:bg-gray-800 shadow-sm" role="tablist" aria-label="Schedule representation view modes">
                                        <button type="button" wire:click="setCandidateViewMode('matrix')" role="tab" aria-selected="{{ $candidateViewMode === 'matrix' ? 'true' : 'false' }}" @class([
                                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-1',
                                            'bg-blue-700 text-white shadow-sm' => $candidateViewMode === 'matrix',
                                            'text-slate-600 hover:text-slate-900 hover:bg-white/60 dark:text-slate-300 dark:hover:text-white dark:hover:bg-gray-700' => $candidateViewMode !== 'matrix',
                                        ])>
                                            <x-filament::icon icon="heroicon-o-calendar-days" class="h-4 w-4" />
                                            <span>Weekly Time-Block Matrix</span>
                                        </button>
                                        <button type="button" wire:click="setCandidateViewMode('table')" role="tab" aria-selected="{{ $candidateViewMode === 'table' ? 'true' : 'false' }}" @class([
                                            'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition-all focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-600 focus-visible:ring-offset-1',
                                            'bg-blue-700 text-white shadow-sm' => $candidateViewMode === 'table',
                                            'text-slate-600 hover:text-slate-900 hover:bg-white/60 dark:text-slate-300 dark:hover:text-white dark:hover:bg-gray-700' => $candidateViewMode !== 'table',
                                        ])>
                                            <x-filament::icon icon="heroicon-o-table-cells" class="h-4 w-4" />
                                            <span>Filterable Meeting Records</span>
                                        </button>
                                    </div>

                                    <!-- Filter Controls -->
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if ($candidateFacultyOptions->isNotEmpty())
                                            <select wire:model.live="candidateFilterFaculty" aria-label="Filter by Faculty Instructor" class="text-xs rounded-lg border-slate-300 py-1.5 px-2.5 bg-white dark:bg-gray-800 dark:border-white/10 dark:text-white shadow-sm font-medium">
                                                <option value="">All Faculty</option>
                                                @foreach ($candidateFacultyOptions as $facId => $facName)
                                                    <option value="{{ $facId }}">{{ $facName }}</option>
                                                @endforeach
                                            </select>
                                        @endif

                                        @if ($candidateRoomOptions->isNotEmpty())
                                            <select wire:model.live="candidateFilterRoom" aria-label="Filter by Room" class="text-xs rounded-lg border-slate-300 py-1.5 px-2.5 bg-white dark:bg-gray-800 dark:border-white/10 dark:text-white shadow-sm font-medium">
                                                <option value="">All Rooms</option>
                                                @foreach ($candidateRoomOptions as $rmId => $rmCode)
                                                    <option value="{{ $rmId }}">{{ $rmCode }}</option>
                                                @endforeach
                                            </select>
                                        @endif

                                        @if ($candidateSectionOptions->isNotEmpty())
                                            <select wire:model.live="candidateFilterSection" aria-label="Filter by Section" class="text-xs rounded-lg border-slate-300 py-1.5 px-2.5 bg-white dark:bg-gray-800 dark:border-white/10 dark:text-white shadow-sm font-medium">
                                                <option value="">All Sections</option>
                                                @foreach ($candidateSectionOptions as $secId => $secCode)
                                                    <option value="{{ $secId }}">{{ $secCode }}</option>
                                                @endforeach
                                            </select>
                                        @endif

                                        @if ($candidateModalityOptions->isNotEmpty())
                                            <select wire:model.live="candidateFilterModality" aria-label="Filter by Modality" class="text-xs rounded-lg border-slate-300 py-1.5 px-2.5 bg-white dark:bg-gray-800 dark:border-white/10 dark:text-white shadow-sm font-medium">
                                                <option value="">All Modalities</option>
                                                @foreach ($candidateModalityOptions as $modKey => $modLabel)
                                                    <option value="{{ $modKey }}">{{ $modLabel }}</option>
                                                @endforeach
                                            </select>
                                        @endif

                                        @if ($candidateFilterFaculty || $candidateFilterRoom || $candidateFilterSection || $candidateFilterModality)
                                            <button type="button" wire:click="resetCandidateFilters" class="text-xs text-blue-700 hover:text-blue-600 font-semibold underline dark:text-blue-400">
                                                Reset Filters
                                            </button>
                                        @endif

                                        <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">
                                            Showing {{ $candidateRows->count() }} of {{ $allCandidateRowsCount }} assignments
                                        </span>
                                    </div>
                                </div>

                                <!-- SUB-VIEW 1: Custom Weekly Time-Block Matrix (Blue-Led) -->
                                @if ($candidateViewMode === 'matrix')
                                    @if ($candidateRows->isEmpty())
                                        <div class="rounded-xl border border-slate-200 p-8 text-center bg-slate-50 dark:border-white/10 dark:bg-white/5">
                                            <p class="text-sm font-medium text-slate-600 dark:text-slate-300">
                                                No candidate assignments match the selected filters.
                                            </p>
                                        </div>
                                    @else
                                        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900" style="min-width: 100%;">
                                            <table class="w-full border-collapse text-left" style="min-width: 780px;" role="table" aria-label="Weekly Timetable Candidate Grid">
                                                <caption class="sr-only">Visual grid of scheduled class meetings across {{ reset($matrixDays) }} to {{ end($matrixDays) }} from {{ sprintf('%02d:00', min($matrixHours)) }} to {{ sprintf('%02d:00', max($matrixHours) + 1) }}</caption>
                                                <thead>
                                                    <tr class="bg-blue-900 text-white dark:bg-blue-950">
                                                        <th scope="col" class="p-2.5 text-xs font-bold uppercase tracking-wider border-r border-blue-800/60 w-20 text-center">Time</th>
                                                        @foreach ($matrixDays as $dayNum => $dayName)
                                                            <th scope="col" class="p-2.5 text-xs font-bold uppercase tracking-wider border-r border-blue-800/60 last:border-r-0">
                                                                {{ $dayName }}
                                                            </th>
                                                        @endforeach
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    @foreach ($matrixHours as $hour)
                                                        @php
                                                            $hourLabel = sprintf('%02d:00', $hour);
                                                        @endphp
                                                        <tr class="border-t border-slate-200 dark:border-slate-800 hover:bg-slate-50/50 dark:hover:bg-white/5">
                                                            <!-- Time Slot Header Cell -->
                                                            <td class="p-2 text-center text-xs font-mono font-medium text-slate-500 border-r border-slate-200 dark:border-slate-800 align-top bg-slate-50/60 dark:bg-slate-900/60">
                                                                {{ $hourLabel }}
                                                            </td>

                                                            <!-- Day Cells -->
                                                            @foreach ($matrixDays as $dayNum => $dayName)
                                                                @php
                                                                    $slotStart = $hour * 60;
                                                                    $slotEnd = ($hour + 1) * 60;

                                                                    $cellRows = $candidateRows->filter(function ($row) use ($dayNum, $slotStart, $slotEnd) {
                                                                        if ((int) $row->day_of_week !== (int) $dayNum) return false;
                                                                        $startH = (int) substr($row->starts_at, 0, 2);
                                                                        $startM = (int) substr($row->starts_at, 3, 2);
                                                                        $endH = (int) substr($row->ends_at, 0, 2);
                                                                        $endM = (int) substr($row->ends_at, 3, 2);
                                                                        $rowStart = ($startH * 60) + $startM;
                                                                        $rowEnd = ($endH * 60) + $endM;

                                                                        return $rowStart < $slotEnd && $rowEnd > $slotStart;
                                                                    });
                                                                @endphp
                                                                <td class="p-1.5 border-r border-slate-200 dark:border-slate-800 last:border-r-0 align-top min-w-[120px]">
                                                                    @if ($cellRows->isNotEmpty())
                                                                        <div class="space-y-1.5">
                                                                            @foreach ($cellRows as $row)
                                                                                @php
                                                                                    $startH = (int) substr($row->starts_at, 0, 2);
                                                                                    $startM = (int) substr($row->starts_at, 3, 2);
                                                                                    $endH = (int) substr($row->ends_at, 0, 2);
                                                                                    $endM = (int) substr($row->ends_at, 3, 2);
                                                                                    $rowStart = ($startH * 60) + $startM;
                                                                                    $rowEnd = ($endH * 60) + $endM;
                                                                                    $durationMinutes = max(0, $rowEnd - $rowStart);
                                                                                    $durationLabel = $durationMinutes % 60 === 0
                                                                                        ? ($durationMinutes / 60) . 'h'
                                                                                        : ($durationMinutes >= 60 ? rtrim(rtrim(sprintf('%.1f', $durationMinutes / 60), '0'), '.') . 'h' : "{$durationMinutes}m");

                                                                                    $isStartSlot = ($rowStart >= $slotStart && $rowStart < $slotEnd);
                                                                                    $isLab = str_contains(strtolower((string) data_get($row, 'schedulingDemand.courseComponent.component_type')), 'lab');
                                                                                    $hasWarning = $row->status === 'warning' || !empty($row->warnings);
                                                                                    $hasConflict = $row->status === 'conflict' || !empty($row->violations);
                                                                                @endphp
                                                                                @if ($isStartSlot)
                                                                                    <div @class([
                                                                                        'rounded-md p-2 text-xs shadow-sm transition-all',
                                                                                        'bg-red-50 text-red-950 border border-red-300 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200' => $hasConflict,
                                                                                        'bg-amber-50 text-amber-950 border border-amber-300 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200' => $hasWarning && !$hasConflict,
                                                                                        'bg-indigo-50/90 text-indigo-950 border border-indigo-200 dark:border-indigo-800 dark:bg-indigo-950/50 dark:text-indigo-200' => $isLab && !$hasWarning && !$hasConflict,
                                                                                        'bg-blue-50/90 text-blue-950 border border-blue-200 dark:border-blue-800 dark:bg-blue-950/50 dark:text-blue-200' => !$isLab && !$hasWarning && !$hasConflict,
                                                                                    ])>
                                                                                        <div class="flex items-center justify-between font-mono font-bold">
                                                                                            <div class="flex items-center gap-1">
                                                                                                <span>{{ substr($row->starts_at, 0, 5) }}–{{ substr($row->ends_at, 0, 5) }}</span>
                                                                                                <span class="text-[10px] font-sans px-1 rounded font-semibold bg-white/70 dark:bg-black/30 text-slate-700 dark:text-slate-300" title="{{ $durationMinutes }} minutes duration">
                                                                                                    {{ $durationLabel }}
                                                                                                </span>
                                                                                            </div>
                                                                                            <span class="text-[10px] font-sans uppercase px-1 rounded font-semibold {{ $isLab ? 'bg-indigo-200/80 text-indigo-900 dark:bg-indigo-900 dark:text-indigo-200' : 'bg-blue-200/80 text-blue-900 dark:bg-blue-900 dark:text-blue-200' }}">
                                                                                                {{ $isLab ? 'LAB' : 'LEC' }}
                                                                                            </span>
                                                                                        </div>
                                                                                        <p class="font-bold mt-1 tracking-tight">
                                                                                            {{ data_get($row, 'schedulingDemand.courseComponent.courseSpecification.course.code', 'Course') }}
                                                                                        </p>
                                                                                        <p class="truncate opacity-90 text-[11px]">
                                                                                            Sec: {{ data_get($row, 'schedulingDemand.sectionDeliveryGroup.section.code', 'Sec') }}
                                                                                        </p>
                                                                                        <div class="mt-1 flex items-center justify-between pt-1 border-t border-slate-200/60 dark:border-white/10 text-[10px]">
                                                                                            <span class="truncate font-semibold">{{ $row->room?->code ?? data_get($row, 'schedulingDemand.modality', 'Room') }}</span>
                                                                                            <span class="truncate ml-1">{{ $row->faculty?->name ?? 'Faculty' }}</span>
                                                                                        </div>
                                                                                    </div>
                                                                                @else
                                                                                    <div @class([
                                                                                        'rounded-md p-1.5 text-xs shadow-sm transition-all border border-dashed opacity-85',
                                                                                        'bg-red-50/70 text-red-950 border-red-300 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200' => $hasConflict,
                                                                                        'bg-amber-50/70 text-amber-950 border-amber-300 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200' => $hasWarning && !$hasConflict,
                                                                                        'bg-indigo-50/70 text-indigo-950 border-indigo-300 dark:border-indigo-800 dark:bg-indigo-950/30 dark:text-indigo-200' => $isLab && !$hasWarning && !$hasConflict,
                                                                                        'bg-blue-50/70 text-blue-950 border-blue-300 dark:border-blue-800 dark:bg-blue-950/30 dark:text-blue-200' => !$isLab && !$hasWarning && !$hasConflict,
                                                                                    ])>
                                                                                        <div class="flex items-center justify-between font-mono text-[10px]">
                                                                                            <span class="opacity-80">{{ substr($row->starts_at, 0, 5) }}–{{ substr($row->ends_at, 0, 5) }}</span>
                                                                                            <span class="font-sans px-1 rounded font-medium bg-white/70 dark:bg-black/30 text-slate-600 dark:text-slate-300">
                                                                                                cont. ({{ $durationLabel }})
                                                                                            </span>
                                                                                        </div>
                                                                                        <p class="font-semibold text-[11px] mt-0.5 truncate">
                                                                                            {{ data_get($row, 'schedulingDemand.courseComponent.courseSpecification.course.code', 'Course') }} · Continuing until {{ substr($row->ends_at, 0, 5) }}
                                                                                        </p>
                                                                                        <div class="mt-0.5 flex items-center justify-between text-[10px] opacity-80">
                                                                                            <span class="truncate">Sec: {{ data_get($row, 'schedulingDemand.sectionDeliveryGroup.section.code', 'Sec') }}</span>
                                                                                            <span class="truncate ml-1 font-medium">{{ $row->room?->code ?? data_get($row, 'schedulingDemand.modality', 'Room') }}</span>
                                                                                        </div>
                                                                                    </div>
                                                                                @endif
                                                                            @endforeach
                                                                        </div>
                                                                    @endif
                                                                </td>
                                                            @endforeach
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                @endif

                                <!-- SUB-VIEW 2: Equivalent Accessible Table -->
                                @if ($candidateViewMode === 'table')
                                    @if ($candidateRows->isEmpty())
                                        <div class="rounded-xl border border-slate-200 p-8 text-center bg-slate-50 dark:border-white/10 dark:bg-white/5">
                                            <p class="text-sm font-medium text-slate-600 dark:text-slate-300">
                                                No candidate assignments match the selected filters.
                                            </p>
                                        </div>
                                    @else
                                        <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                                            <table class="w-full text-left text-xs border-collapse" role="table" aria-label="Candidate Schedule Meeting Records">
                                                <caption class="sr-only">Detailed list of candidate class meetings with course, section, schedule times, rooms, instructors, and conflict status.</caption>
                                                <thead>
                                                    <tr class="bg-blue-900 text-white dark:bg-blue-950">
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Day</th>
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Time</th>
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Duration</th>
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Course</th>
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Section</th>
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Modality</th>
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Room</th>
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Faculty Instructor</th>
                                                        <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                                    @foreach ($candidateRows as $row)
                                                        @php
                                                            $startH = (int) substr($row->starts_at, 0, 2);
                                                            $startM = (int) substr($row->starts_at, 3, 2);
                                                            $endH = (int) substr($row->ends_at, 0, 2);
                                                            $endM = (int) substr($row->ends_at, 3, 2);
                                                            $rowDurMin = max(0, (($endH * 60) + $endM) - (($startH * 60) + $startM));
                                                            $rowDurLbl = $rowDurMin % 60 === 0
                                                                ? ($rowDurMin / 60) . 'h'
                                                                : ($rowDurMin >= 60 ? rtrim(rtrim(sprintf('%.1f', $rowDurMin / 60), '0'), '.') . 'h' : "{$rowDurMin}m");
                                                        @endphp
                                                        <tr class="hover:bg-slate-50/70 dark:hover:bg-white/5">
                                                            <td class="p-2.5 font-medium">{{ \App\Models\SectionMeeting::dayOptions()[$row->day_of_week] ?? $row->day_of_week }}</td>
                                                            <td class="p-2.5 font-mono tabular-nums">{{ substr($row->starts_at, 0, 5) }} – {{ substr($row->ends_at, 0, 5) }}</td>
                                                            <td class="p-2.5 font-mono tabular-nums font-semibold text-slate-700 dark:text-slate-300">{{ $rowDurLbl }}</td>
                                                            <td class="p-2.5 font-semibold text-slate-900 dark:text-white">
                                                                {{ data_get($row, 'schedulingDemand.courseComponent.courseSpecification.course.code') }}
                                                            </td>
                                                            <td class="p-2.5">{{ data_get($row, 'schedulingDemand.sectionDeliveryGroup.section.code') }}</td>
                                                            <td class="p-2.5">
                                                                <span class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold bg-slate-100 text-slate-800 dark:bg-gray-800 dark:text-gray-300">
                                                                    {{ str(data_get($row, 'schedulingDemand.modality', 'f2f'))->headline() }}
                                                                </span>
                                                            </td>
                                                            <td class="p-2.5 font-semibold">{{ $row->room?->code ?? '-' }}</td>
                                                            <td class="p-2.5">{{ $row->faculty?->name ?? 'Unassigned' }}</td>
                                                            <td class="p-2.5">
                                                                <span @class([
                                                                    'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' => $row->status === 'ok',
                                                                    'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300' => $row->status === 'warning',
                                                                    'bg-red-100 text-red-800 dark:bg-red-950/60 dark:text-red-300' => $row->status === 'conflict',
                                                                ])>
                                                                    <span class="sr-only">Status: </span>{{ str($row->status)->headline() }}
                                                                </span>
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        @else
                            <!-- Empty State: No runs yet for this Term -->
                            <div class="rounded-xl border border-dashed border-slate-300 p-12 text-center bg-slate-50/60 dark:border-slate-700 dark:bg-white/5 space-y-4">
                                <x-filament::icon icon="heroicon-o-cpu-chip" class="mx-auto h-12 w-12 text-slate-400" />
                                <div class="space-y-1">
                                    <h3 class="text-base font-bold text-slate-900 dark:text-white">No Timetable Generated Yet</h3>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 max-w-md mx-auto">
                                        Captures current ready schedule requirements and dispatches them to the configured Google CP-SAT timetable generator.
                                    </p>
                                </div>
                                @if (!$readOnly)
                                    <button type="button" wire:click="mountAction('generateTimetable')" class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-blue-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-700">
                                        <x-filament::icon icon="heroicon-o-paper-airplane" class="h-4 w-4" />
                                        <span>Generate Timetable</span>
                                    </button>
                                @endif
                            </div>
                        @endif
                    </article>
                @elseif ($viewTab === 'published')
                    <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900 space-y-6">
                        <!-- Institutional Header & Context -->
                        <div class="border-b border-slate-200 pb-4 dark:border-white/10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold tracking-wider text-blue-900 uppercase dark:text-blue-300">Servitech Institute Asia Inc. · Office of the University Registrar</span>
                                    <span class="text-[10px] text-slate-400">·</span>
                                    <span class="text-[10px] font-medium text-slate-500 dark:text-slate-400">Powered by TALA</span>
                                </div>
                                <h2 class="text-xl font-bold tracking-tight text-slate-900 dark:text-white mt-0.5">Official Published Timetable</h2>
                                <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
                                    Authoritative, immutable schedule approved for official student registration and faculty teaching load for <strong>{{ $term->label }}</strong>.
                                </p>
                            </div>
                            @if ($versions->count() > 1)
                                <div class="flex items-center gap-2">
                                    <label for="version-selector" class="text-xs font-semibold text-slate-600 dark:text-slate-400 whitespace-nowrap">Timetable Version:</label>
                                    <select id="version-selector" wire:change="selectPublishedVersion($event.target.value)" class="text-xs rounded-lg border-slate-300 py-1.5 px-3 bg-white dark:bg-gray-800 dark:border-white/10 dark:text-white font-medium shadow-sm">
                                        @foreach ($versions as $verOption)
                                            <option value="{{ $verOption->id }}" @selected($selectedVersion?->id === $verOption->id)>
                                                Version {{ $verOption->version }} ({{ $verOption->state }}) · {{ $verOption->published_at?->format('M j, Y') }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            @endif
                        </div>

                        @if ($selectedVersion)
                            <!-- Official Published Banner -->
                            <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 p-4 dark:border-emerald-900/40 dark:bg-emerald-950/20 flex flex-col md:flex-row md:items-center md:justify-between gap-4 shadow-sm">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="inline-flex items-center rounded-md bg-emerald-800 px-2.5 py-1 text-xs font-bold text-white shadow-sm">
                                            Official Version {{ $selectedVersion->version }}
                                        </span>
                                        <span class="inline-flex items-center rounded-md bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-300">
                                            {{ $selectedVersion->state }}
                                        </span>
                                    </div>
                                    <p class="text-sm text-emerald-950 dark:text-emerald-200">
                                        <strong>Sign-Off Authority:</strong> {{ $selectedVersion->authority_reference ?? 'Registrar Attributable Sign-off' }}
                                    </p>
                                    <p class="text-xs text-emerald-800/80 dark:text-emerald-400">
                                        Published on {{ $selectedVersion->published_at?->format('F j, Y · g:i A') }} · {{ $publishedMeetings->count() }} official meetings scheduled
                                    </p>
                                </div>

                                <a href="{{ route('timetable.version.print', $selectedVersion) }}" target="_blank" class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white shadow hover:bg-emerald-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 shrink-0">
                                    <x-filament::icon icon="heroicon-o-printer" class="h-4 w-4" />
                                    <span>Print Official A4 Timetable (Landscape)</span>
                                </a>
                            </div>

                            <!-- Published Meetings Table -->
                            <div class="space-y-3">
                                <h3 class="text-base font-semibold text-slate-900 dark:text-white">Scheduled Class Meetings</h3>
                                @if ($publishedMeetings->isEmpty())
                                    <p class="text-sm text-slate-500">No meetings recorded in this published timetable version.</p>
                                @else
                                    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                                        <table class="w-full text-left text-xs border-collapse" role="table" aria-label="Official Scheduled Class Meetings">
                                            <caption class="sr-only">Authoritative list of scheduled class meetings for this published timetable version.</caption>
                                            <thead>
                                                <tr class="bg-blue-900 text-white dark:bg-blue-950">
                                                    <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Day</th>
                                                    <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Time</th>
                                                    <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Course</th>
                                                    <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Class Offering</th>
                                                    <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Modality</th>
                                                    <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Room</th>
                                                    <th scope="col" class="p-2.5 font-bold uppercase tracking-wider">Faculty Instructor</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                                @foreach ($publishedMeetings as $meeting)
                                                    <tr class="hover:bg-slate-50/70 dark:hover:bg-white/5">
                                                        <td class="p-2.5 font-medium">{{ \App\Models\SectionMeeting::dayOptions()[$meeting->day_of_week] ?? $meeting->day_of_week }}</td>
                                                        <td class="p-2.5 font-mono tabular-nums">{{ substr($meeting->starts_at, 0, 5) }} – {{ substr($meeting->ends_at, 0, 5) }}</td>
                                                        <td class="p-2.5 font-semibold text-slate-900 dark:text-white">
                                                            {{ data_get($meeting, 'schedulingDemand.courseComponent.courseSpecification.course.code') }}
                                                        </td>
                                                        <td class="p-2.5 font-medium">{{ $meeting->classOffering?->code }}</td>
                                                        <td class="p-2.5">
                                                            <span class="inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold bg-slate-100 text-slate-800 dark:bg-gray-800 dark:text-gray-300">
                                                                {{ str($meeting->modality)->headline() }}
                                                            </span>
                                                        </td>
                                                        <td class="p-2.5 font-semibold">{{ $meeting->room?->code ?? $meeting->location_label ?? '-' }}</td>
                                                        <td class="p-2.5">{{ $meeting->faculty?->name ?? 'Unassigned' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>

                            <!-- Version History Table -->
                            @if ($versions->isNotEmpty())
                                <div class="space-y-3 pt-4 border-t border-slate-200 dark:border-white/10">
                                    <h3 class="text-sm font-semibold text-slate-900 dark:text-white">Immutable Timetable Version Archive</h3>
                                    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                                        <table class="w-full text-left text-xs border-collapse" role="table" aria-label="Timetable Version History">
                                            <caption class="sr-only">Archive of all published timetable versions and their sign-off authority references.</caption>
                                            <thead>
                                                <tr class="bg-slate-100 text-slate-700 dark:bg-gray-800 dark:text-slate-200">
                                                    <th scope="col" class="p-2.5 font-semibold">Version</th>
                                                    <th scope="col" class="p-2.5 font-semibold">State</th>
                                                    <th scope="col" class="p-2.5 font-semibold">Authority Reference</th>
                                                    <th scope="col" class="p-2.5 font-semibold">Meetings</th>
                                                    <th scope="col" class="p-2.5 font-semibold">Published At</th>
                                                    <th scope="col" class="p-2.5 font-semibold text-right">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-slate-200 dark:divide-slate-800">
                                                @foreach ($versions as $v)
                                                    <tr class="hover:bg-slate-50/70 dark:hover:bg-white/5">
                                                        <td class="p-2.5 font-bold font-mono">v{{ $v->version }}</td>
                                                        <td class="p-2.5">
                                                            <span @class([
                                                                'inline-flex rounded px-1.5 py-0.5 text-[10px] font-semibold',
                                                                'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300' => $v->state === 'Published',
                                                                'bg-slate-100 text-slate-800 dark:bg-gray-800 dark:text-gray-300' => $v->state !== 'Published',
                                                            ])>
                                                                {{ $v->state }}
                                                            </span>
                                                        </td>
                                                        <td class="p-2.5 font-medium">{{ $v->authority_reference ?? '-' }}</td>
                                                        <td class="p-2.5 font-mono tabular-nums">{{ $v->meetings_count }}</td>
                                                        <td class="p-2.5">{{ $v->published_at?->format('M j, Y g:i A') }}</td>
                                                        <td class="p-2.5 text-right space-x-2">
                                                            @if ($selectedVersion->id !== $v->id)
                                                                <button type="button" wire:click="selectPublishedVersion({{ $v->id }})" class="text-blue-700 hover:text-blue-600 font-semibold underline dark:text-blue-400">
                                                                    View
                                                                </button>
                                                            @endif
                                                            <a href="{{ route('timetable.version.print', $v) }}" target="_blank" class="text-emerald-700 hover:text-emerald-600 font-semibold underline dark:text-emerald-400">
                                                                A4 Landscape
                                                            </a>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            @endif
                        @else
                            <!-- Empty State: No published version yet -->
                            <div class="rounded-xl border border-dashed border-slate-300 p-12 text-center bg-slate-50/60 dark:border-slate-700 dark:bg-white/5 space-y-4">
                                <x-filament::icon icon="heroicon-o-calendar-days" class="mx-auto h-12 w-12 text-slate-400" />
                                <div class="space-y-1">
                                    <h3 class="text-base font-bold text-slate-900 dark:text-white">No Official Timetable Published Yet</h3>
                                    <p class="text-sm text-slate-600 dark:text-slate-400 max-w-md mx-auto">
                                        An official timetable version is created when the Registrar signs off on an accepted candidate under the Generate & Review tab.
                                    </p>
                                </div>
                                <button type="button" wire:click="showTab('generate')" class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-blue-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-700">
                                    <x-filament::icon icon="heroicon-o-arrow-left" class="h-4 w-4" />
                                    <span>Go to Generate & Review</span>
                                </button>
                            </div>
                        @endif
                    </article>
                @else
                    <article class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <h2 class="text-lg font-semibold text-gray-950 dark:text-white">{{ $tabs[$viewTab] }}</h2>
                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                            @switch($viewTab)
                                @case('overview') Review approved dates, windows, teaching grid, exceptions, and authority evidence. @break
                                @case('classes') Confirm cohorts and Regular or authority-backed Additional Class Offerings. Sharing is recorded through explicit cohort relationships. @break
                                @case('resources') Review Faculty declarations, qualifications, rooms, features, institutional unavailability, and commitments. Faculty records their own availability from My Availability. @break
                            @endswitch
                        </p>
                        <a href="{{ $destinations[$viewTab] }}" class="mt-4 inline-flex rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2">
                            Open {{ $tabs[$viewTab] }} records
                        </a>
                        @if ($readOnly)
                            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">Read-only oversight: publication and correction controls remain unavailable.</p>
                        @endif
                    </article>
                @endif
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
