<x-filament-panels::page class="tala-term-workspace">
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        @php
            $workingRun = $allRuns->first(fn ($run) => $run->status !== 'published');
            $reviewEvidence = $activeRun?->reviewEvidence();
        @endphp
        <div class="tala-work-toolbar">
            <x-tala-navigation label="Official timetable or work in progress">
                @if ($currentVersion)<x-filament::tabs.item type="button" wire:click="chooseTimetable('official:{{ $currentVersion->id }}')" :active="$showOfficial" :aria-pressed="$showOfficial">Official timetable · v{{ $currentVersion->version }}</x-filament::tabs.item>@endif
                @if ($workingRun)<x-filament::tabs.item type="button" wire:click="chooseTimetable('draft:{{ $workingRun->id }}')" :active="! $showOfficial" :aria-pressed="! $showOfficial">Work in progress</x-filament::tabs.item>@endif
            </x-tala-navigation>
            <p class="tala-work-footnote">{{ $currentVersion ? 'Official v'.$currentVersion->version.' is the schedule currently in use.' : 'No official timetable has been published.' }}</p>
        </div>
        @if ($solverFailure || ! empty($solverOutcome['message']))<div class="tala-guide-info tala-guide-warning" role="status">{{ $solverFailure['message'] ?? $solverOutcome['message'] }}</div>@endif
        @if ($activeRun && ! $showOfficial && in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true))<p class="tala-guide-info" wire:poll.5s.visible role="status">Generating this Term’s timetable from recorded scope. You can return here for its status.</p>@endif
        <div class="tala-guide-timetable-layout">
            <section class="tala-task-work tala-guide-calendar-pane" aria-label="Scheduled meetings">
                @if ($timetablePresentation && $scopeMeetingCount)
                    <div class="tala-work-toolbar tala-guide-timetable-toolbar">
                        <div class="tala-guide-filter">
                            <label for="timetable-view">Show meetings for</label>
                            <x-filament::input.wrapper><x-filament::input.select id="timetable-view" wire:change="showTimetableView($event.target.value)">@foreach (['whole' => 'Whole Term', 'section' => 'Student section', 'faculty' => 'Teacher', 'room' => 'Room', 'list' => 'Meeting list'] as $mode => $label)<option value="{{ $mode }}" @selected($timetableView === $mode)>{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
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
                        @if ($programFilter || $yearFilter || $viewFilter)<x-filament::button color="gray" wire:click="clearViewFilters">Clear filters</x-filament::button>@endif
                    </div>
                    <p class="tala-work-footnote">{{ count($timetablePresentation['meetings']) }} of {{ $scopeMeetingCount }} meetings shown. Filters change this view only; generation and publication cover the complete Term.</p>
                    @if (! count($timetablePresentation['meetings']))<p>No meetings match this view. <x-filament::button color="gray" wire:click="clearViewFilters">Clear filters</x-filament::button></p>
                    @elseif ($timetableView === 'list'){{ $this->table }}
                    @elseif (in_array($timetableView, ['whole', 'section', 'faculty', 'room']) && ($timetableView === 'whole' || $viewFilter))
                        <x-tala-timetable :presentation="$timetablePresentation" focused controlled embedded native-details wire:key="guide-week-{{ $showOfficial ? 'official-'.$selectedVersion?->id : 'attempt-'.$activeRun?->id }}-{{ $timetableView }}-{{ md5(($programFilter ?? '').'|'.($yearFilter ?? '').'|'.($viewFilter ?? '')) }}" />
                        <div class="tala-guide-course-legend" aria-label="Course colors">@foreach (collect($timetablePresentation['meetings'])->unique('courseCode') as $meeting)<span data-course-color="{{ $timetablePresentation['courseColors'][$meeting['courseCode']] }}"><i aria-hidden="true"></i>{{ $meeting['courseTitle'] ?: $meeting['courseCode'] }}</span>@endforeach</div>
                    @else<p>{{ match ($timetableView) {'section' => 'Choose a student section to see its weekly meetings.', 'faculty' => 'Choose a teacher to see their weekly meetings.', default => 'Choose a room to see its weekly meetings.'} }}</p>@endif
                @else
                    <div class="tala-guide-empty-work">
                        <h2>{{ $activeRun && ! $showOfficial ? (in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true) ? 'Preparing the meetings' : 'No meetings are available from this draft.') : 'No timetable yet' }}</h2>
                        <p>{{ $activeRun && ! $showOfficial ? ($reviewEvidence['meaning'] ?? 'Inspect the result checks and correct the source before trying again.') : ($this->generationReadiness()['reason'] ?? 'Recorded scope is ready for a timetable.') }}</p>
                        @if (! $activeRun && ! $this->generationReadiness()['ready'])
                            @php($nextTab = $this->generationReadiness()['tab'] ?? 'plan')
                            <x-filament::button tag="a" color="gray" :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => $nextTab])">{{ match ($nextTab) { 'classes' => 'Review classes', 'resources' => 'Review Faculty and rooms', default => 'Review teaching calendar' } }}</x-filament::button>
                        @endif
                    </div>
                @endif
            </section>
            <aside class="tala-guide-result-pane" aria-label="Timetable result and next action">
                @if ($showOfficial && $selectedVersion)
                    <h2>{{ $selectedVersion->state === 'Published' ? 'Official timetable' : 'Previous official timetable' }} · v{{ $selectedVersion->version }}</h2>
                    @if ($timetablePresentation['synthetic'] ?? false)<p>Mode: Synthetic review data</p>@endif
                    <p class="tala-guide-status is-ready">{{ $selectedVersion->state }}</p>
                    <p>Published {{ $selectedVersion->published_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} (Asia/Manila).</p>
                    @if ($selectedVersion->authority_reference)<p>Approval reference: {{ $selectedVersion->authority_reference }}</p>@endif
                    <p>Changes are prepared and reviewed separately. This issued version stays unchanged.</p>
                    <x-filament::button tag="a" icon="heroicon-o-printer" :href="route('timetable.version.print', ['version' => $selectedVersion])" target="_blank">Print official timetable</x-filament::button>
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $selectedVersion->schedule_run_id, 'termId' => $term->id])">Publication record</x-filament::button>
                @elseif ($activeRun)
                    <h2>Attempt #{{ $activeRun->id }}</h2>
                    <p class="tala-guide-status needs-work">{{ $activeRun->candidate_state ? str($activeRun->candidate_state)->headline() : (\App\Models\ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? $activeRun->status) }} · Not official</p>
                    @if ($timetablePresentation['synthetic'] ?? false)<p>Mode: Synthetic review data</p>@endif
                    <dl class="tala-guide-result-checks">
                        <div><dt>Solver result</dt><dd>{{ $reviewEvidence['outcome'] }}</dd></div>
                        <div><dt>Meeting completeness</dt><dd>{{ $reviewEvidence['coverage'] }}</dd></div>
                        <div><dt>Conflict checks</dt><dd>{{ $reviewEvidence['conflicts'] }}</dd></div>
                    </dl>
                    <p>{{ $reviewEvidence['meaning'] }}</p>
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $activeRun->id, 'termId' => $term->id])">{{ $scopeMeetingCount ? 'Inspect assignments and validation' : 'Inspect generation diagnostics and validation findings' }}</x-filament::button>
                    @if (! $readOnly)
                        @if ($this->canReviewActiveRun())<div class="tala-guide-result-decisions"><p>Review the assignments and checks, then record your decision.</p>{{ $this->acceptCandidateAction->label('Accept timetable')->color('primary') }}{{ $this->rejectCandidateAction->label('Reject result')->color('danger')->outlined() }}</div>@endif
                        @if ($this->canPublishActiveRun()){{ $this->publishOfficialTimetableAction }}@endif
                        @if ($this->canRetryActiveRun()){{ $this->retrySolverRunAction }}@endif
                    @endif
                @else
                    <h2>Next step</h2><p>Complete the recorded preparation checks before generating the timetable.</p>
                @endif
                @if ($this->retainedCandidateNextStep())<p class="tala-work-footnote" role="status">{{ $this->retainedCandidateNextStep() }}</p>@elseif (! $readOnly && ($activeRun || $versions->isNotEmpty() || $this->generationReadiness()['ready'])){{ $this->generateTimetableAction->label($activeRun || $versions->isNotEmpty() ? 'Generate another attempt' : 'Generate timetable')->color($activeRun || $versions->isNotEmpty() ? 'gray' : 'primary') }}@endif
            </aside>
        </div>
        @if ($activeRun && ! $showOfficial)
            <section class="tala-guide-coverage" aria-labelledby="result-measures-heading"><h2 id="result-measures-heading">Result measures</h2><p class="tala-work-footnote">Request runtime: {{ $activeRun->runtime_ms === null ? 'Not measured' : number_format($activeRun->runtime_ms).' ms' }}</p><dl class="tala-evidence-facts">@foreach ($activeRun->qualityPresentation() as $measure)<div><dt>{{ $measure['label'] }}</dt><dd>{{ $measure['value'] }}</dd></div>@endforeach</dl><p class="tala-work-footnote">Faculty load imbalance includes every eligible teacher captured by the request, including teachers with no assignments.</p></section>
        @endif
        @if ($allRuns->where('status', '!=', 'published')->count() > 1 || $versions->count() > 1)
            <section class="tala-guide-coverage" aria-labelledby="timetable-history-heading"><h2 id="timetable-history-heading">Earlier timetable attempts & versions</h2><p class="tala-work-footnote">Opening an earlier result does not change the official timetable.</p>
                <ul class="tala-guide-history">@foreach ($allRuns->where('status', '!=', 'published') as $run)<li><span><strong>Attempt #{{ $run->id }}</strong> · {{ $run->created_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ \App\Models\ScheduleGenerationRun::statusOptions()[$run->status] ?? $run->status }}</span><x-filament::button color="gray" wire:click="chooseTimetable('draft:{{ $run->id }}')" :disabled="! $showOfficial && $activeRun?->id === $run->id">{{ ! $showOfficial && $activeRun?->id === $run->id ? 'Viewing this attempt' : 'Open attempt' }}</x-filament::button></li>@endforeach
                @foreach ($versions as $version)<li><span><strong>{{ $version->state }} v{{ $version->version }}</strong> · {{ $version->published_at?->timezone('Asia/Manila')->format('M j, Y') }}</span><x-filament::button color="gray" wire:click="chooseTimetable('official:{{ $version->id }}')" :disabled="$showOfficial && $selectedVersion?->id === $version->id">Open version</x-filament::button></li>@endforeach</ul>
            </section>
        @endif
    @else<p>Select a Term to inspect its timetable.</p>@endif
    <x-filament-actions::modals />
</x-filament-panels::page>
