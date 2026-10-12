<x-filament-panels::page>
    @include('filament.pages.partials.term-workspace-header')
    @if ($term)
        <section class="tala-task-work" aria-label="Timetable work">
        <div class="tala-timetable-state text-sm" role="status">
            @if ($timetablePresentation['synthetic'] ?? false)<p class="text-xs text-gray-600 dark:text-gray-300">Synthetic review data</p>@endif
            @if ($showOfficial && $selectedVersion)<p><strong>{{ $selectedVersion->state === 'Published' ? 'Official' : 'Superseded official' }} v{{ $selectedVersion->version }}</strong> · Published {{ $selectedVersion->published_at?->timezone('Asia/Manila')->format('M j, Y') }}@if ($activeRun && $activeRun->status !== 'published') · A newer draft does not replace this official version.@endif</p>
            @elseif ($activeRun)<p><strong>Draft · {{ \App\Models\ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? $activeRun->status }}</strong> · Not official · {{ $publicationSummary['conflicts'] }} recorded clashes · {{ $publicationSummary['warnings'] }} warnings. Review the meetings and findings before accepting. Acceptance revalidates the draft; publishing separately records the approved official version.</p>
            @else<p>No timetable recorded. {{ $this->generationReadiness()['reason'] ?? 'Recorded scope is ready for a draft.' }} <x-filament::link :href="\App\Filament\Pages\TermOverview::getUrl(['termId' => $term->id])">Overview</x-filament::link></p>@endif
            @if ($solverFailure || ! empty($solverOutcome['message']))<p>{{ $solverFailure['message'] ?? $solverOutcome['message'] }}</p>@endif
            @if ($activeRun && in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true))<p wire:poll.5s.visible>Generating this Term’s timetable from recorded scope. You can return here for its status.</p>@endif
            @if ($scopeMeetingCount)<p class="text-gray-600 dark:text-gray-300">{{ $scopeMeetingCount }} meetings in captured whole-Term scope · Showing {{ $programFilter ?: 'all Programs' }}{{ $yearFilter ? ' · '.$yearFilter : '' }}{{ $viewFilter ? ' · selected '.$timetableView : '' }}. Filters affect this view only.</p>@endif
        </div>
        <div class="tala-work-toolbar">
            <div class="tala-version-control"><label for="planning-version" class="text-sm font-medium">Timetable version</label><x-filament::input.wrapper><x-filament::input.select id="planning-version" wire:change="chooseTimetable($event.target.value)">
                @if ($allRuns->isEmpty() && $versions->isEmpty())<option>No timetable yet</option>@endif
                @foreach ($allRuns->where('status', '!=', 'published') as $run)<option value="draft:{{ $run->id }}" @selected(! $showOfficial && $activeRun?->id === $run->id)>Draft · {{ $run->created_at->timezone('Asia/Manila')->format('M j, g:i A') }} · {{ \App\Models\ScheduleGenerationRun::statusOptions()[$run->status] ?? $run->status }}</option>@endforeach
                @foreach ($versions as $version)<option value="official:{{ $version->id }}" @selected($showOfficial && $selectedVersion?->id === $version->id)>{{ $version->state === 'Published' ? 'Official' : 'Superseded' }} v{{ $version->version }}</option>@endforeach
            </x-filament::input.select></x-filament::input.wrapper></div>
            <div class="flex flex-wrap gap-2">
                @if (($showOfficial && $selectedVersion) || (! $showOfficial && $activeRun))<x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $showOfficial ? $selectedVersion->schedule_run_id : $activeRun->id, 'termId' => $term->id])">{{ $showOfficial ? 'Review revision and publication history' : 'Inspect assignments and validation' }}</x-filament::button>@endif
                @if ($showOfficial && $selectedVersion)<x-filament::button tag="a" color="gray" :href="route('timetable.version.print', ['version' => $selectedVersion])" target="_blank" icon="heroicon-o-printer">Print official timetable</x-filament::button>
                @elseif ($activeRun && ! $readOnly)
                    @if ($this->canReviewActiveRun()){{ $this->acceptCandidateAction->label('Accept draft') }} {{ $this->rejectCandidateAction->label('Reject draft') }}@endif
                    @if ($this->canPublishActiveRun()){{ $this->publishOfficialTimetableAction }}@endif
                    @if ($this->canRetryActiveRun()){{ $this->retrySolverRunAction }}@endif
                @endif
                @if (! $readOnly){{ $this->generateTimetableAction->label($activeRun ? 'New draft' : 'Generate draft')->color($activeRun ? 'gray' : 'primary') }}@endif
            </div>
        </div>
        @if ($timetablePresentation && $scopeMeetingCount)
            <div class="tala-work-toolbar"><x-tala-navigation label="Timetable views">@foreach (['whole' => 'Whole Term', 'section' => 'Student section', 'faculty' => 'Teacher', 'room' => 'Room', 'list' => 'Meeting list'] as $mode => $label)<x-filament::tabs.item type="button" wire:click="showTimetableView('{{ $mode }}')" :active="$timetableView === $mode" :aria-pressed="$timetableView === $mode">{{ $label }}</x-filament::tabs.item>@endforeach</x-tala-navigation>
                <details class="tala-view-filters" wire:ignore.self><summary>Filters</summary><div class="flex flex-wrap gap-3">
                    <label class="text-sm font-medium">Program<x-filament::input.wrapper><x-filament::input.select wire:model.live="programFilter"><option value="">All Programs</option>@foreach ($programOptions as $program)<option value="{{ $program }}">{{ $program }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <label class="text-sm font-medium">Curriculum year<x-filament::input.wrapper><x-filament::input.select wire:model.live="yearFilter"><option value="">All curriculum years</option>@foreach ($yearOptions as $year)<option value="{{ $year }}">{{ $year }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>
                    <x-filament::button color="gray" wire:click="clearViewFilters">Clear filters</x-filament::button>
                </div></details>
                @if ($viewOptions)<label class="text-sm font-medium">{{ match ($timetableView) {'section' => 'Student section', 'faculty' => 'Teacher', default => 'Room'} }}<x-filament::input.wrapper><x-filament::input.select wire:model.live="viewFilter"><option value="">Choose one</option>@foreach ($viewOptions as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper></label>@endif
                @if ($timetableView === 'whole'){{ $this->expandTimetableAction }}@endif
            </div>
        @endif
        @if ($timetablePresentation && $scopeMeetingCount)
            @if (! count($timetablePresentation['meetings']))<p>No meetings match this view. <x-filament::button color="gray" wire:click="clearViewFilters">Clear filters</x-filament::button></p>
            @elseif ($timetableView === 'whole')<x-tala-timetable-master :presentation="$timetablePresentation" :groups="$masterGroups" native-details wire:key="master-timetable-{{ $showOfficial ? 'official-'.$selectedVersion?->id : 'draft-'.$activeRun?->id }}-{{ md5(($programFilter ?? '').'|'.($yearFilter ?? '')) }}" />
            @elseif ($timetableView === 'list'){{ $this->table }}
            @elseif ($viewFilter)<x-tala-timetable :presentation="$timetablePresentation" focused controlled embedded native-details wire:key="focused-timetable-{{ $showOfficial ? 'official-'.$selectedVersion?->id : 'draft-'.$activeRun?->id }}-{{ $timetableView }}-{{ $viewFilter }}" />
            @else<p>{{ match ($timetableView) {'section' => 'Choose a student section to see its weekly meetings.', 'faculty' => 'Choose a teacher to see their weekly meetings.', default => 'Choose a room to see its weekly meetings.'} }}</p>@endif
        @endif
        @if ($activeRun && ! $showOfficial && ! $scopeMeetingCount && ! in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true))<p>No meetings are available from this draft. <x-filament::link :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $activeRun->id, 'termId' => $term->id])">Inspect generation diagnostics and validation findings</x-filament::link></p>@endif
        @if ($activeRun || $selectedVersion)<details class="tala-planning-details"><summary>Source, validation and history</summary><div class="space-y-3 text-sm">
            @if ($showOfficial && $selectedVersion)<p>{{ $selectedVersion->authority_reference }} · Published {{ $selectedVersion->published_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} (Asia/Manila)</p><x-filament::link :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $selectedVersion->schedule_run_id, 'termId' => $term->id])">Publication evidence and controlled revisions</x-filament::link>
            @elseif ($activeRun)<p>Runtime: {{ $activeRun->runtime_ms === null ? 'Not measured' : number_format($activeRun->runtime_ms).' ms' }} · Quality: {{ $qualityMeasures ? 'Recorded below' : 'Not measured' }}</p>@if ($qualityMeasures)<dl class="tala-planning-facts">@foreach ($qualityMeasures as $key => $value)<div><dt>{{ str($key)->replace('_',' ')->ucfirst() }}</dt><dd>{{ is_scalar($value) ? $value : 'See source evidence' }}</dd></div>@endforeach</dl>@endif<x-filament::link :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $activeRun, 'termId' => $term->id])">Assignment corrections, validation and run history</x-filament::link>@endif
            <p>Student-group associations and teaching geometry come from the captured request; current record labels supplement the source.</p>
        </div></details>@endif
        </section>
    @else<p>Select a Term to inspect its timetable.</p>@endif
    <x-filament-actions::modals />
</x-filament-panels::page>
