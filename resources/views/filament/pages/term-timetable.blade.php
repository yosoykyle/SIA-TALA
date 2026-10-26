<x-filament-panels::page class="tala-term-workspace tala-timetable-workspace">
    @if ($term)
        @php
            $workingRun = $allRuns->first(fn ($run) => $run->status !== 'published');
            $reviewEvidence = $activeRun?->reviewEvidence();
            $hasTimetableRecord = $activeRun || ($showOfficial && $selectedVersion);
            $pendingGeneration = ! $showOfficial && $activeRun && in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true);
            $viewScopeLabel = match ($timetableView) {
                'section' => $viewOptions[$viewFilter] ?? 'Choose a student section',
                'faculty' => $viewOptions[$viewFilter] ?? 'Choose a teacher',
                'room' => $viewOptions[$viewFilter] ?? 'Choose a room',
                default => 'Whole Term',
            };
            $termContext = collect([$term->typeLabel(), $term->academicYear?->label])->filter()->implode(' · ');
            $timetableStateLabel = $showOfficial && $selectedVersion
                ? ($selectedVersion->state === 'Published' ? 'Official timetable' : 'Previous official timetable').' · v'.$selectedVersion->version
                : ($activeRun ? match ($activeRun->candidate_state) {
                    'Accepted' => 'Candidate accepted',
                    'Rejected' => 'Candidate rejected',
                    'Stale', 'Superseded' => 'Candidate needs current sources',
                    default => match ($activeRun->status) {
                        \App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching => 'Timetable generation in progress',
                        \App\Models\ScheduleGenerationRun::StatusFailed => 'Timetable attempt failed',
                        \App\Models\ScheduleGenerationRun::StatusBlocked => 'Timetable attempt blocked',
                        \App\Models\ScheduleGenerationRun::StatusUnderReview => 'Candidate under review',
                        default => \App\Models\ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? $activeRun->status,
                    },
                } : null);
        @endphp
        <div class="tala-timetable-studio" x-data="{ dockMinimized: false }">
            <header class="tala-studio-heading">
                <div class="tala-studio-breadcrumbs">
                    <x-filament::breadcrumbs :breadcrumbs="[
                        \App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true]) => 'Term planning',
                        \App\Filament\Pages\TermOverview::getUrl(['termId' => $term->id]) => $term->typeLabel(),
                        'Timetable',
                    ]" />
                </div>
                <div class="tala-studio-identity">
                    <div class="tala-studio-title"><h1>Timetable</h1></div>
                    <p class="tala-studio-term" title="{{ $termContext }}">{{ $termContext }}</p>
                    @if ($timetableStateLabel)
                        <p class="tala-studio-current-state">{{ $timetableStateLabel }}@if ($activeRun && ! $showOfficial) · Attempt #{{ $activeRun->id }} · Not official @endif</p>
                    @endif
                </div>
                <div class="tala-studio-viewing" aria-label="Timetable viewing controls">
                    @if ($timetablePresentation && $scopeMeetingCount)
                        <x-filament::tabs label="Timetable format" class="tala-studio-format">
                            <x-filament::tabs.item :active="$timetableFormat === 'week'" wire:click="showTimetableFormat('week')" icon="heroicon-o-calendar-days" aria-label="View weekly timetable">Week</x-filament::tabs.item>
                            <x-filament::tabs.item :active="$timetableFormat === 'list'" wire:click="showTimetableFormat('list')" icon="heroicon-o-list-bullet" aria-label="View meeting list">List</x-filament::tabs.item>
                        </x-filament::tabs>
                        <x-filament::button color="gray" icon="heroicon-o-adjustments-horizontal" x-on:click="$dispatch('open-modal', { id: 'timetable-view-filters' })">View &amp; filters</x-filament::button>
                    @endif
                    <x-filament::button color="gray" icon="heroicon-o-information-circle" x-on:click="$dispatch('open-modal', { id: 'timetable-term-details' })">Term details</x-filament::button>
                    @if ($timetablePresentation && $scopeMeetingCount)
                        <div class="tala-guide-filter tala-studio-search">
                            <label for="timetable-meeting-search" class="sr-only">Search meetings</label>
                            <x-filament::input.wrapper prefix-icon="heroicon-m-magnifying-glass"><x-filament::input id="timetable-meeting-search" type="search" placeholder="Search meetings" maxlength="255" wire:model.live.debounce.500ms="tableSearch" /></x-filament::input.wrapper>
                        </div>
                    @endif
                </div>
            </header>
            @if ($hasTimetableRecord)
                <section class="tala-studio-status" aria-label="Timetable result">
                    <div wire:loading.delay.long wire:target="chooseTimetable,showTimetableView,showTimetableFormat,viewFilter,programFilter,yearFilter,tableSearch,clearViewFilters" role="status"><p class="tala-studio-message"><x-filament::loading-indicator aria-hidden="true" />Updating timetable view…</p></div>
                    @if ($activeRun && ! $showOfficial && ! $pendingGeneration)
                        <p class="tala-studio-validation"><x-filament::icon :icon="$reviewEvidence['validated'] ? 'heroicon-o-check-circle' : 'heroicon-o-exclamation-triangle'" />
                            <span>{{ \Illuminate\Support\Str::before($reviewEvidence['coverage'], ' independently validated at the recorded check') }} · {{ $reviewEvidence['conflicts'] }}.</span>
                        </p>
                        <p class="tala-studio-message" role="status"><x-filament::icon icon="heroicon-o-information-circle" /><span>{{ $reviewEvidence['outcome'] }}. <button type="button" x-on:click="$dispatch('open-modal', { id: 'timetable-review-details' })">View evidence</button></span></p>
                    @endif
                    @if ($timetablePresentation['synthetic'] ?? false)<p class="tala-studio-record">Synthetic review data</p>@endif
                    @if ($readOnly)<p class="tala-studio-record">Read-only · Registrar maintains these records.</p>@endif
                    @if ($solverFailure || ! empty($solverOutcome['message']))<p class="tala-guide-info tala-guide-warning" role="status">{{ $solverFailure['message'] ?? $solverOutcome['message'] }}</p>@endif

                </section>
            @endif

            <section class="tala-studio-board" aria-label="Scheduled meetings">
                @if ($timetablePresentation && $scopeMeetingCount)
                    <p class="tala-work-footnote"><strong>{{ $viewScopeLabel }}</strong>@if ($programFilter) · {{ $programFilter }} @endif @if ($yearFilter) · {{ $yearFilter }} @endif · {{ count($timetablePresentation['meetings']) }} of {{ $scopeMeetingCount }} meetings shown · Asia/Manila</p>
                    <div @class(['tala-studio-canvas', 'is-week' => $timetableFormat === 'week'])>
                        @if (! count($timetablePresentation['meetings']))<p>No meetings match this view. <x-filament::button color="gray" wire:click="clearViewFilters">Clear filters</x-filament::button></p>
                        @elseif ($timetableFormat === 'list' && ($timetableView === 'whole' || $viewFilter)){{ $this->table }}
                        @elseif (in_array($timetableView, ['whole', 'section', 'faculty', 'room']) && ($timetableView === 'whole' || $viewFilter))
                            <x-tala-timetable :presentation="$timetablePresentation" focused controlled embedded native-details :show-window="false" wire:key="guide-week-{{ $showOfficial ? 'official-'.$selectedVersion?->id : 'attempt-'.$activeRun?->id }}-{{ $timetableView }}-{{ md5(($programFilter ?? '').'|'.($yearFilter ?? '').'|'.($viewFilter ?? '').'|'.$tableSearch) }}" />
                        @else<p>{{ match ($timetableView) {'section' => 'Choose a student section to see its weekly meetings.', 'faculty' => 'Choose a teacher to see their weekly meetings.', default => 'Choose a room to see its weekly meetings.'} }}</p>@endif
                    </div>
                @elseif ($pendingGeneration)
                    <x-tala-operation-status :run="$activeRun" wire:poll.5s.visible />
                @else
                    <div class="tala-guide-empty-work tala-planning-timetable-empty">
                        <x-filament::icon icon="heroicon-o-calendar-days" />
                        <h2>{{ $activeRun && ! $showOfficial ? (in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true) ? 'Preparing the meetings' : 'No meetings are available from this draft.') : 'No timetable yet' }}</h2>
                        <p>{{ $activeRun && ! $showOfficial ? ($reviewEvidence['meaning'] ?? 'Inspect the result checks and correct the source before trying again.') : ($this->generationReadiness()['reason'] ?? 'Recorded scope is ready for a timetable.') }}</p>
                        @if (! $activeRun && ! $this->generationReadiness()['ready'])
                            @php($nextTab = $this->generationReadiness()['tab'] ?? 'plan')
                            <x-filament::button tag="a" :icon="match ($nextTab) { 'classes' => 'heroicon-o-book-open', 'resources' => 'heroicon-o-user-group', default => 'heroicon-o-calendar-days' }" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $nextTab])">{{ match ($nextTab) { 'classes' => 'Review classes', 'resources' => 'Review Faculty and rooms', default => 'Review teaching calendar' } }}</x-filament::button>
                        @endif
                        @if ($activeRun && ! $showOfficial)<x-filament::button tag="a" color="gray" icon="heroicon-o-clipboard-document-check" :href="$this->timetableReviewUrl($activeRun->id)">Review findings and recovery</x-filament::button>@endif
                        @if (! $activeRun && ! $readOnly && $this->generationReadiness()['ready']){{ $this->generateTimetableAction }}@endif
                    </div>
                @endif
            </section>

            @if ($hasTimetableRecord)
                <section class="tala-studio-dock tala-task-surface" :class="{ 'is-minimized': dockMinimized }" aria-label="Timetable review controls">
                    <x-filament::button color="gray" icon="heroicon-o-chevron-down" class="tala-studio-dock-toggle" x-ref="hideControls" x-show="! dockMinimized" x-on:click="dockMinimized = true; $nextTick(() => requestAnimationFrame(() => $refs.showControls.focus({ preventScroll: true })))" aria-expanded="true" aria-controls="timetable-dock-actions">
                        <span>Hide controls</span>
                    </x-filament::button>
                    <x-filament::button color="primary" icon="heroicon-o-chevron-up" class="tala-studio-dock-toggle" x-ref="showControls" x-cloak x-show="dockMinimized" x-on:click="dockMinimized = false; $nextTick(() => requestAnimationFrame(() => $refs.hideControls.focus({ preventScroll: true })))" aria-expanded="false" aria-controls="timetable-dock-actions">
                        <span>Review controls</span>
                    </x-filament::button>
                    <div id="timetable-dock-actions" class="tala-studio-dock-actions" x-show="! dockMinimized">
                        <div class="tala-studio-review-context">
                            <span @class(['sr-only' => $showOfficial])>{{ $showOfficial ? ($selectedVersion?->state === 'Published' ? 'Current issued version' : 'Historical issued version') : ($readOnly ? 'Read-only evidence' : 'Whole-Term decision') }}</span>
                            <div class="tala-guide-filter tala-studio-source">
                                <label for="timetable-source" class="sr-only">Timetable record</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select id="timetable-source" wire:change="chooseTimetable($event.target.value)">
                                        @foreach ($allRuns->where('status', '!=', 'published') as $run)
                                            <option value="draft:{{ $run->id }}" @selected(! $showOfficial && $activeRun?->id === $run->id)>Attempt #{{ $run->id }} · {{ \App\Models\ScheduleGenerationRun::statusOptions()[$run->status] ?? $run->status }}</option>
                                        @endforeach
                                        @foreach ($versions as $version)
                                            <option value="official:{{ $version->id }}" @selected($showOfficial && $selectedVersion?->id === $version->id)>{{ $version->state === 'Published' ? 'Official timetable' : 'Previous official timetable' }} · v{{ $version->version }}</option>
                                        @endforeach
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </div>
                        </div>
                        <div class="tala-studio-utilities">
                            @if (! $showOfficial && $activeRun && $scopeMeetingCount)
                                <x-filament::button tag="a" color="gray" icon="heroicon-o-pencil-square" :href="$this->timetableReviewUrl($activeRun->id).'#timetable-run-meetings'">Review assignments</x-filament::button>
                            @else
                                <x-filament::button color="gray" icon="heroicon-o-clipboard-document-check" x-on:click="$dispatch('open-modal', { id: 'timetable-review-details' })">Evidence &amp; history</x-filament::button>
                            @endif
                            @if ($showOfficial && $selectedVersion)
                                <x-filament::button tag="a" color="gray" icon="heroicon-o-printer" :href="route('timetable.version.print', ['version' => $selectedVersion, 'timetableView' => $timetableView, 'timetableFormat' => $timetableFormat, 'viewFilter' => $viewFilter, 'programFilter' => $programFilter, 'yearFilter' => $yearFilter, 'tableSearch' => $tableSearch])" target="_blank">Print whole Term</x-filament::button>
                            @endif
                        </div>
                        @if (! $readOnly && $activeRun && ! $showOfficial)
                            @if ($this->canReviewActiveRun())
                                <div class="tala-studio-review-commands">
                                    {{ $this->acceptCandidateAction->label('Accept timetable')->color('primary') }}
                                    {{ $this->rejectCandidateAction->label('Reject candidate') }}
                                </div>
                            @endif
                            @if ($this->canPublishActiveRun()){{ $this->publishOfficialTimetableAction }}@endif
                            @if ($this->canRetryActiveRun()){{ $this->retrySolverRunAction }}@endif
                        @endif
                    </div>
                </section>
                <x-filament::modal footer-actions-alignment="end" id="timetable-view-filters" width="xl" teleport="body">
                    <x-slot name="heading">View &amp; filters</x-slot>
                    <div class="tala-studio-view-panel">
                                    <div class="tala-guide-filter">
                                        <label for="timetable-view">Show meetings for</label>
                                        <x-filament::input.wrapper><x-filament::input.select id="timetable-view" wire:change="showTimetableView($event.target.value)">@foreach (['whole' => 'Whole Term', 'section' => 'Student section', 'faculty' => 'Teacher', 'room' => 'Room'] as $mode => $label)<option value="{{ $mode }}" @selected($timetableView === $mode)>{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                                    </div>
                                    @if ($viewOptions)
                                        <div class="tala-guide-filter">
                                            <label for="timetable-view-filter">{{ match ($timetableView) {'section' => 'Student section', 'faculty' => 'Teacher', default => 'Room'} }}</label>
                                            <x-filament::input.wrapper><x-filament::input.select id="timetable-view-filter" wire:model.live="viewFilter"><option value="">Choose one</option>@foreach ($viewOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                                        </div>
                                    @endif
                                    <div class="tala-guide-filter">
                                        <label for="timetable-program-filter">Program</label>
                                        <x-filament::input.wrapper><x-filament::input.select id="timetable-program-filter" wire:model.live="programFilter"><option value="">All Programs</option>@foreach ($programOptions as $program)<option value="{{ $program }}">{{ $program }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                                    </div>
                                    <div class="tala-guide-filter">
                                        <label for="timetable-year-filter">Year level</label>
                                        <x-filament::input.wrapper><x-filament::input.select id="timetable-year-filter" wire:model.live="yearFilter"><option value="">All year levels</option>@foreach ($yearOptions as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                                    </div>
                                    <p class="tala-work-footnote">Filters change this view only; generation and publication cover the complete Term.</p>
                                    @if ($programFilter || $yearFilter || $viewFilter || filled($tableSearch))<x-filament::button color="gray" wire:click="clearViewFilters">Clear filters</x-filament::button>@endif
                                </div>
                    <x-slot name="footerActions"><x-filament::button color="primary" x-on:click="$dispatch('close-modal', { id: 'timetable-view-filters' })">Show timetable</x-filament::button></x-slot>
                </x-filament::modal>
            @endif
            <x-filament::modal footer-actions-alignment="end" id="timetable-term-details" width="2xl" teleport="body">
                <x-slot name="heading">Term details</x-slot>
                <div class="tala-studio-details">
                    <section class="tala-evidence-surface" aria-label="Term identity and dates">
                        <div class="tala-evidence-title"><x-filament::icon icon="heroicon-o-calendar-days" /><div><span class="tala-work-footnote">{{ $term->academicYear?->label ?? 'Academic year not recorded' }}</span><h2>{{ $term->typeLabel() }}</h2></div></div>
                        <dl class="tala-evidence-facts">
                            <div class="tala-evidence-wide"><dt>Recorded name</dt><dd>{{ $term->label }}</dd></div>
                            <div><dt>Term dates</dt><dd>{{ $term->starts_on->format('M j, Y') }} – {{ $term->ends_on->format('M j, Y') }}</dd></div>
                            <div><dt>Record status</dt><dd><x-filament::badge color="gray">{{ \App\Models\Term::stateOptions()[$term->state] ?? $term->state }}</x-filament::badge></dd></div>
                        </dl>
                    </section>
                    <section class="tala-evidence-scope">
                        <h3><x-filament::icon icon="heroicon-o-globe-alt" />School-wide planning</h3>
                        <p>All programs and year levels. Recorded classes determine the timetable.</p>
                        <p class="tala-work-footnote">Filters change this view only; generation and publication cover the complete Term.</p>
                    </section>
                </div>
                <x-slot name="footerActions">
                    <x-filament::button tag="a" color="gray" icon="heroicon-o-arrows-right-left" :href="\App\Filament\Pages\TermOverview::getUrl(['chooseTerm' => true])">Change Term</x-filament::button>
                    <x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'timetable-term-details' })">Close</x-filament::button>
                </x-slot>
            </x-filament::modal>
            @if ($hasTimetableRecord)
                <x-filament::modal footer-actions-alignment="end" id="timetable-review-details" width="4xl" teleport="body">
                    <x-slot name="heading">Evidence &amp; history</x-slot>
                    <div class="tala-studio-details">
                        <section class="tala-studio-evidence" aria-label="Timetable result and next action">
                            <div class="tala-evidence-title">
                                <x-filament::icon :icon="$showOfficial ? 'heroicon-o-check-badge' : 'heroicon-o-document-text'" />
                                <div><h2>{{ $timetableStateLabel }}</h2><p class="tala-work-footnote">@if ($showOfficial)Issued timetable version {{ $selectedVersion->version }}@else Attempt #{{ $activeRun->id }} · Not official @endif</p></div>
                            </div>
                            @if ($timetablePresentation['synthetic'] ?? false)<p class="tala-work-footnote">Mode: Synthetic review data</p>@endif
                            @if ($showOfficial && $selectedVersion)
                                <section class="tala-evidence-surface" aria-label="Publication facts">
                                    <h3>Publication record <x-filament::badge :color="$selectedVersion->state === 'Published' ? 'success' : 'gray'">{{ $selectedVersion->state }}</x-filament::badge></h3>
                                    <dl class="tala-evidence-facts">
                                        <div><dt>Published (Asia/Manila)</dt><dd>{{ $selectedVersion->published_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }}</dd></div>
                                        @if ($selectedVersion->authority_reference)<div><dt>Approval reference</dt><dd>{{ $selectedVersion->authority_reference }}</dd></div>@endif
                                    </dl>
                                    <p class="tala-work-footnote">Changes are prepared and reviewed separately. This issued version stays unchanged.</p>
                                </section>
                                <div class="tala-evidence-actions">
                                    <x-filament::button tag="a" color="gray" icon="heroicon-o-printer" :href="route('timetable.version.print', ['version' => $selectedVersion, 'timetableView' => $timetableView, 'timetableFormat' => $timetableFormat, 'viewFilter' => $viewFilter, 'programFilter' => $programFilter, 'yearFilter' => $yearFilter, 'tableSearch' => $tableSearch])" target="_blank">Print whole Term</x-filament::button>
                                    <x-filament::button tag="a" color="gray" icon="heroicon-o-document-text" :href="$this->timetableReviewUrl($selectedVersion->schedule_run_id)">Publication record</x-filament::button>
                                </div>
                            @elseif ($activeRun)
                                <dl class="tala-evidence-checks">
                                    <div><x-filament::icon icon="heroicon-o-cpu-chip" /><div><dt>Solver result</dt><dd>{{ $reviewEvidence['outcome'] }}</dd></div></div>
                                    <div><x-filament::icon icon="heroicon-o-calendar-days" /><div><dt>Meeting completeness</dt><dd>{{ $reviewEvidence['coverage'] }}</dd></div></div>
                                    <div><x-filament::icon icon="heroicon-o-shield-check" /><div><dt>Conflict checks</dt><dd>{{ $reviewEvidence['conflicts'] }}</dd></div></div>
                                </dl>
                                <p class="tala-evidence-meaning">{{ $reviewEvidence['meaning'] }}</p>
                                <div class="tala-evidence-actions"><x-filament::button tag="a" color="primary" icon="heroicon-o-magnifying-glass" :href="$this->timetableReviewUrl($activeRun->id)">{{ $scopeMeetingCount ? 'Inspect assignments and validation' : 'Inspect generation diagnostics and validation findings' }}</x-filament::button></div>
                            @endif
                            @if ($showOfficial && $workingRun)
                                <div class="tala-evidence-source-switch"><div><h3>Latest timetable attempt</h3><p class="tala-work-footnote">Review changes separately from this issued version.</p></div><x-filament::button color="gray" icon="heroicon-o-document-text" wire:click="chooseTimetable('draft:{{ $workingRun->id }}')">Review latest attempt</x-filament::button></div>
                            @elseif (! $showOfficial && $currentVersion)
                                <div class="tala-evidence-source-switch"><div><h3>Current official timetable</h3><p class="tala-work-footnote">Version {{ $currentVersion->version }} stays in use.</p></div><x-filament::button color="gray" wire:click="chooseTimetable('official:{{ $currentVersion->id }}')">View official timetable · v{{ $currentVersion->version }}</x-filament::button></div>
                            @endif
                        </section>
                    @if (! $readOnly && ! $this->retainedCandidateNextStep() && ($activeRun || $versions->isNotEmpty() || $this->generationReadiness()['ready']))
                        {{ $this->generateTimetableAction->label($activeRun || $versions->isNotEmpty() ? 'Generate another attempt' : 'Generate timetable')->color($activeRun || $versions->isNotEmpty() ? 'gray' : 'primary') }}
                    @endif
                    @if ($timetablePresentation && count($timetablePresentation['meetings']))
                        <details class="tala-planning-coverage"><summary>Course colors</summary><div class="tala-planning-reveal"><div class="tala-guide-course-legend" aria-label="Course colors">@foreach (collect($timetablePresentation['meetings'])->unique('courseCode') as $meeting)<span data-course-color="{{ $timetablePresentation['courseColors'][$meeting['courseCode']] }}"><i aria-hidden="true"></i>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</span>@endforeach</div></div></details>
                    @endif
                    @if ($activeRun && ! $showOfficial)
                        @if ($this->canPublishActiveRun())<p class="tala-work-footnote">Accepted, not published yet. Publication rechecks independent validation, source freshness and Registrar authority for the whole Term.@if ($currentVersion) The current official version stays in use.@endif</p>@endif
                        @if (! $readOnly && $this->retainedCandidateNextStep())<p class="tala-work-footnote" role="status">{{ $this->retainedCandidateNextStep() }}</p>@endif
                    @endif
                            @if ($activeRun && ! $showOfficial)
            <details class="tala-planning-coverage"><summary>Result measures</summary>
<div class="tala-planning-reveal"><p class="tala-work-footnote">Request runtime: {{ $activeRun->runtime_ms === null ? 'Not measured' : number_format($activeRun->runtime_ms).' ms' }}</p><dl class="tala-evidence-facts">@foreach ($activeRun->qualityPresentation() as $measure)<div><dt>{{ $measure['label'] }}</dt><dd>{{ $measure['value'] }}</dd></div>@endforeach</dl><p class="tala-work-footnote">Faculty load imbalance includes every eligible teacher captured by the request, including teachers with no assignments.</p>
</div>
</details>
        @endif
        @if ($allRuns->where('status', '!=', 'published')->count() > 1 || $versions->count() > 1)
            <details class="tala-planning-coverage"><summary>Earlier timetable attempts &amp; versions</summary>
<div class="tala-planning-reveal"><p class="tala-work-footnote">Opening an earlier result does not change the official timetable.</p>
                <ul class="tala-guide-history">@foreach ($allRuns->where('status', '!=', 'published') as $run)<li><span><strong>Attempt #{{ $run->id }}</strong> · {{ $run->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ \App\Models\ScheduleGenerationRun::statusOptions()[$run->status] ?? $run->status }}</span><x-filament::button color="gray" wire:click="chooseTimetable('draft:{{ $run->id }}')" :disabled="! $showOfficial && $activeRun?->id === $run->id">{{ ! $showOfficial && $activeRun?->id === $run->id ? 'Viewing this attempt' : 'Open attempt' }}</x-filament::button></li>@endforeach
                @foreach ($versions as $version)<li><span><strong>{{ $version->state }} v{{ $version->version }}</strong> · {{ $version->published_at?->timezone('Asia/Manila')->format('M j, Y') }}</span><x-filament::button color="gray" wire:click="chooseTimetable('official:{{ $version->id }}')" :disabled="$showOfficial && $selectedVersion?->id === $version->id">Open version</x-filament::button></li>@endforeach</ul>

</div>
</details>
        @endif

                    </div>
                    <x-slot name="footerActions"><x-filament::button color="gray" x-on:click="$dispatch('close-modal', { id: 'timetable-review-details' })">Close</x-filament::button></x-slot>
                </x-filament::modal>
            @endif
        </div>
    @else
        @include('filament.pages.partials.term-workspace-header')
        <p>Select a Term to inspect its timetable.</p>
    @endif
    <x-filament-actions::modals />
</x-filament-panels::page>
