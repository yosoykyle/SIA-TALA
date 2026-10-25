<x-filament::section>

    <x-slot name="heading">Review the proposed timetable</x-slot>

    <x-slot name="description">Inspect the class meetings and validation findings. Accepting records your review; publishing makes an accepted candidate official.</x-slot>

    @if ($activeRun)

        @php($generationPending = in_array($activeRun->status, [\App\Models\ScheduleGenerationRun::StatusQueued, \App\Models\ScheduleGenerationRun::StatusDispatching], true))

        <div class="tala-planning-next"><div>

            <h3>{{ \App\Models\ScheduleGenerationRun::statusOptions()[$activeRun->status] ?? $activeRun->status }} @if ($activeRun->candidate_state && ! in_array($activeRun->candidate_state, ['Under Review', 'UnderReview'], true)) · {{ $activeRun->candidate_state }} @endif</h3>

            <p>{{ $allCandidateRowsCount }} proposed meeting(s). @if ($generationPending) Generation is in progress. The result will appear here when the request finishes. @elseif ($activeRun->status === 'published') This request was published; use Published Timetable for its official version. @elseif ($activeRun->candidate_state === 'Accepted') Your review is recorded. Record the external sign-off to publish. @elseif ($allCandidateRowsCount) Check the weekly relationships and each meeting before accepting. @else No timetable is available to review from this request. @endif</p>

        </div><div class="flex flex-wrap gap-2">

            @if (! $readOnly)

                @if ($this->canReviewActiveRun()){{ $this->acceptCandidateAction->color('primary') }} {{ $this->rejectCandidateAction->outlined() }}@endif

                @if ($this->canPublishActiveRun()){{ $this->publishOfficialTimetableAction->color('primary') }}@endif

                @if ($this->canRetryActiveRun()){{ $this->retrySolverRunAction }}@endif

            @endif

        </div></div>

        @if ($solverFailure || ! empty($solverOutcome['message']))<p role="status" class="mt-4 text-sm">{{ $solverFailure['message'] ?? $solverOutcome['message'] ?? '' }}</p>@endif

        @if ($generationPending)

            <p wire:poll.5s.visible role="status" class="mt-5 text-sm">Waiting for the timetable generator. This status refreshes automatically while visible. You can continue preparing this Term and return here to review the result.</p>

        @elseif ($allCandidateRowsCount)

            <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">{{ $publicationSummary['conflicts'] }} recorded conflict(s) · {{ $publicationSummary['warnings'] }} warning(s). Acceptance independently rechecks the candidate; these counts alone do not establish publication readiness.</p>

            <div class="mt-5"><x-tala-timetable :presentation="$candidatePresentation" wire:key="candidate-timetable-{{ $activeRun->id }}" /></div>

        @else

            <div class="mt-5 space-y-3"><h3 class="font-semibold">Resolve the inputs, then request another candidate</h3>
                @foreach ($solverOutcome['conflicts'] ?? $solverOutcome['findings'] ?? [] as $finding)<p class="text-sm">{{ $finding['description'] ?? $finding['message'] ?? 'Review the recorded validation finding.' }}</p>@endforeach
                <p class="text-sm">Review the meeting requirements and their source inputs. A failed request cannot be accepted or published.</p>
                <x-filament::button tag="a" color="gray" :href="$classSources->firstWhere('resource', \App\Filament\Resources\SchedulingDemands\SchedulingDemandResource::class)['url']">Open meeting requirements</x-filament::button>

                @foreach ($readiness['blockers'] ?? [] as $blocker)<p class="text-sm">{{ $blocker['reason'] }} <x-filament::link :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => match ($blocker['code']) {'calendar_not_active' => 'overview', 'classes_not_confirmed' => 'classes', default => 'resources'}])">Open the source</x-filament::link></p>@endforeach

            </div>

        @endif

        <details class="tala-planning-details"><summary>Other requests, quality and review evidence</summary><div class="tala-planning-reveal mt-4 space-y-4">

            @if ($allRuns->count() > 1)<div><label class="text-sm font-medium" for="planning-run">Timetable request</label><select id="planning-run" wire:change="selectRun($event.target.value)" class="tala-planning-select">@foreach ($allRuns as $option)<option value="{{ $option->id }}" @selected($option->id === $activeRun->id)>{{ $option->created_at->timezone('Asia/Manila')->format('M j, g:i A') }} · {{ \App\Models\ScheduleGenerationRun::statusOptions()[$option->status] ?? $option->status }} · #{{ $option->id }}</option>@endforeach</select></div>@endif

            <dl class="tala-planning-facts"><div><dt>Captured request</dt><dd>#{{ $activeRun->id }} · {{ $activeRun->created_at->timezone('Asia/Manila')->format('M j, Y · g:i A') }}</dd></div>@if ($solverFailure)<div><dt>Failure reference</dt><dd>{{ $solverFailure['code'] ?? 'No reference recorded' }}</dd></div>@endif<div><dt>Generator outcome</dt><dd>{{ $solverOutcome['status'] ?? $solverOutcome['solver_status'] ?? 'Not recorded' }}</dd></div><div><dt>Quality measurements</dt><dd>{{ $qualityMeasures ? 'Recorded below' : 'Not measured' }}</dd></div><div><dt>Request runtime</dt><dd>{{ $activeRun->runtime_ms === null ? 'Not measured' : number_format($activeRun->runtime_ms).' ms' }}</dd></div></dl>

            @if ($qualityMeasures)<dl class="tala-planning-facts">@foreach ($qualityMeasures as $key => $value)<div><dt>{{ str($key)->replace('_', ' ')->ucfirst() }}</dt><dd>{{ is_scalar($value) ? $value : 'See full review evidence' }}</dd></div>@endforeach</dl>@endif

            <x-filament::link :href="\App\Filament\Resources\ScheduleGenerationRuns\ScheduleGenerationRunResource::getUrl('view', ['record' => $activeRun, 'termId' => $term->id])">Open full review evidence</x-filament::link>

            @if ($this->retainedCandidateNextStep())<p class="tala-work-footnote" role="status">{{ $this->retainedCandidateNextStep() }}</p>@elseif (! $readOnly){{ $this->generateTimetableAction->label('Generate another candidate')->color('gray')->outlined() }}@endif

        </div></details>

    @else

        <h3 class="font-semibold">No timetable candidate yet</h3><p class="mt-2 text-sm">Prepare this Term's classes and resources. Generation captures and validates those inputs before proposing meeting times.</p>

        @if ($readiness && ! $readiness['ready'])<ul class="mt-4 space-y-3 text-sm">@foreach ($readiness['blockers'] as $blocker)<li>{{ $blocker['reason'] }} <x-filament::link :href="\App\Filament\Pages\TermPlanningWorkbench::getUrl(['termId' => $term->id, 'viewTab' => match ($blocker['code']) {'calendar_not_active' => 'overview', 'classes_not_confirmed' => 'classes', default => 'resources'}])">Review source</x-filament::link></li>@endforeach</ul>@endif

        @if ($this->retainedCandidateNextStep())<p class="tala-work-footnote" role="status">{{ $this->retainedCandidateNextStep() }}</p>@elseif (! $readOnly)<div class="mt-5">{{ $this->generateTimetableAction }}</div>@endif

    @endif

</x-filament::section>

